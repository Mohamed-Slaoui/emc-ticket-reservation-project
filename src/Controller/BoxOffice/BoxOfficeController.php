<?php

namespace App\Controller\BoxOffice;

use App\Entity\Performance;
use App\Entity\Reservation;
use App\Enum\InventoryStatus;
use App\Enum\EventStatus;
use App\Enum\PaymentMethod;
use App\Enum\ReservationStatus;
use App\Enum\SalesChannel;
use App\Enum\TariffCode;
use App\Exception\ReservationEngineException;
use App\Exception\SeatNotAvailableException;
use App\Exception\TariffNotAuthorizedException;
use App\Repository\BookingPolicyRepository;
use App\Repository\PerformancePlaceRepository;
use App\Repository\PerformancePriceRepository;
use App\Repository\PerformanceRepository;
use App\Repository\ReservationRepository;
use App\Repository\TariffRepository;
use App\Repository\TicketRepository;
use App\Service\Booking\PerformanceLabelService;
use App\Service\Layout\SeatMapViewBuilder;
use App\Service\Payment\PaymentService;
use App\Service\Reservation\ReservationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The box-office counter — the seller's desk.
 *
 * Same seat map, same engine, same tariffs as the online channel (INV-10,
 * docs/WORKFLOWS.md WF-2). The counter sells the reduced and invitation
 * tariffs — verified and authorised in person — and records the payment
 * immediately: cash or card terminal, per assumption A1 (a simulated online
 * payment, plus cash / terminal at the counter).
 *
 * The reduced tariff requires the supporting document to be verified in
 * person (Q5); the invitation tariff requires an explicit authorization
 * from the seller (BR-11); the group tariff needs at least 5 seats, all at
 * the group rate (BR-12). Every rule is checked server-side.
 */
#[Route('/box-office')]
class BoxOfficeController extends AbstractController
{
    #[Route('', name: 'box_office_index', methods: ['GET'])]
    public function index(
        Request $request,
        PerformanceRepository $performances,
        PerformancePlaceRepository $places,
        PerformanceLabelService $labels,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_BOX_OFFICE');

        $query = trim($request->query->getString('q'));
        $shows = $performances->findOnSale();

        if ('' !== $query) {
            $needle = mb_strtolower($query);
            $shows = array_values(array_filter(
                $shows,
                static fn (Performance $performance): bool => str_contains(
                    mb_strtolower($performance->getEvent()->getTitle().' '.$performance->getSeatingLayout()->getRoom()->getName().' '.$performance->getSeatingLayout()->getRoom()->getVenue()->getName()),
                    $needle,
                )));
        }

        $available = [];

        foreach ($shows as $performance) {
            $available[(int) $performance->getId()] = $places->countByStatus($performance, InventoryStatus::Available);
        }

        return $this->render('box_office/index.html.twig', [
            'performances' => $shows,
            'available' => $available,
            'q' => $query,
            'labels' => $labels->labelsFor($shows),
        ]);
    }

    #[Route('/performances/{id}', name: 'box_office_performance', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function performance(
        #[MapEntity(id: 'id')] Performance $performance,
        SeatMapViewBuilder $builder,
        TariffRepository $tariffs,
        BookingPolicyRepository $policies,
        PerformanceLabelService $labels,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_BOX_OFFICE');
        $this->assertPublished($performance);

        [$rows, $zoneColors, $zonePrices, $fromAmount, $availableCount, $totalCount, $zones, $pricesByTariff] = $builder->build($performance, TariffCode::Full);

        // Only the tariffs the seller is authorized to apply are offered.
        $offered = $this->authorizedTariffs($tariffs);

        return $this->render('box_office/performance.html.twig', [
            'performance' => $performance,
            'label' => $labels->labelFor($performance),
            'rows' => $rows,
            'zones' => $zones,
            'zoneColors' => $zoneColors,
            'zonePrices' => $zonePrices,
            'fromAmount' => $fromAmount,
            'availableCount' => $availableCount,
            'totalCount' => $totalCount,
            'pricesByTariff' => $pricesByTariff,
            'tariffs' => $offered,
            'holdMinutes' => $policies->findOneBy([])?->getHoldDurationMinutes() ?? 10,
        ]);
    }

    #[Route('/performances/{id}/sell', name: 'box_office_sell', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function sell(
        Request $request,
        #[MapEntity(id: 'id')] Performance $performance,
        PaymentService $paymentService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_BOX_OFFICE');
        $this->assertPublished($performance);

        if (!$this->isCsrfTokenValid('sell_'.$performance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('box_office_performance', ['id' => $performance->getId()]);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw new \LogicException('The user is not signed in.');
        }

        // The tariff and the counter authorisation stay server-side.
        $tariffCode = $this->tariffChoice($request);

        if (null === $tariffCode) {
            $this->addFlash('danger', 'Choose a valid tariff before selling.');

            return $this->redirectToRoute('box_office_performance', ['id' => $performance->getId()]);
        }

        $eligibilityVerified = $request->request->getBoolean('eligibility_verified');
        $paymentMethod = PaymentMethod::tryFrom((string) $request->request->get('payment_method', PaymentMethod::Cash->value)) ?? PaymentMethod::Cash;

        $placeIds = $request->request->all('places');

        try {
            $reservation = $paymentService->sellAtCounter(
                $performance,
                array_map('intval', is_array($placeIds) ? $placeIds : []),
                $tariffCode,
                $user,
                $user,
                $eligibilityVerified,
                $paymentMethod,
            );
        } catch (TariffNotAuthorizedException | SeatNotAvailableException | ReservationEngineException $exception) {
            $this->addFlash('warning', $exception->getMessage());

            return $this->redirectToRoute('box_office_performance', ['id' => $performance->getId()]);
        }

        $this->addFlash('success', sprintf('Sale completed — %s.', $reservation->getReference()));

        return $this->redirectToRoute('box_office_reservation', ['id' => $reservation->getId()]);
    }

    #[Route('/reservations', name: 'box_office_reservations', methods: ['GET'])]
    public function reservations(
        Request $request,
        ReservationRepository $reservations,
        PerformanceLabelService $labels,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_BOX_OFFICE');

        $query = trim($request->query->getString('q'));
        $results = $reservations->searchForStaff($query);

        return $this->render('box_office/reservations.html.twig', [
            'reservations' => $results,
            'q' => $query,
            'labels' => $labels->labelsFor(array_map(
                static fn (Reservation $reservation): Performance => $reservation->getPerformance(),
                $results,
            )),
        ]);
    }

    #[Route('/reservations/{id}', name: 'box_office_reservation', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function reservation(
        #[MapEntity(id: 'id')] Reservation $reservation,
        TicketRepository $tickets,
        PerformanceLabelService $labels,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_BOX_OFFICE');

        $ticketMap = [];

        foreach (ReservationStatus::Confirmed === $reservation->getStatus() ? $tickets->findForReservation($reservation) : [] as $ticket) {
            $ticketMap[$ticket->getReservationItem()->getId()] = $ticket;
        }

        return $this->render('box_office/reservation.html.twig', [
            'reservation' => $reservation,
            'label' => $labels->labelFor($reservation->getPerformance()),
            'tickets' => $ticketMap,
        ]);
    }

    private function assertPublished(Performance $performance): void
    {
        if (EventStatus::Published !== $performance->getEvent()->getStatus()) {
            throw $this->createNotFoundException();
        }
    }

    private function tariffChoice(Request $request): ?TariffCode
    {
        try {
            return TariffCode::from((string) $request->request->get('tariff', ''));
        } catch (\ValueError) {
            return null;
        }
    }

    /**
     * The tariffs the signed-in seller is authorized to apply.
     *
     * FULL and GROUP are always offered at the counter (the group rule is
     * enforced by the engine — BR-12). The administrator may apply all four
     * tariffs. A seller needs a permissions for reduced and invitation — the
     * administrator grants them, and they are enforced server-side as well.
     *
     * @return list<\App\Entity\Tariff>
     */
    private function authorizedTariffs(TariffRepository $tariffs): array
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();

        if (!$user instanceof \App\Entity\User) {
            throw new \LogicException('The user is not signed in.');
        }

        $isAdministrator = $this->isGranted('ROLE_ADMIN');

        return array_values(array_filter(
            $tariffs->catalogue(),
            static fn (\App\Entity\Tariff $tariff): bool => match ($tariff->getCode()) {
                TariffCode::Full->value, TariffCode::Group->value => true,
                TariffCode::Reduced->value => $isAdministrator || $user->canSellReducedTariff(),
                TariffCode::Invitation->value => $isAdministrator || $user->canSellInvitationTariff(),
                default => false,
            },
        ));
    }
}
