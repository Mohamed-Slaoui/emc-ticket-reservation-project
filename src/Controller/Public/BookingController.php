<?php

namespace App\Controller\Public;

use App\Entity\Performance;
use App\Entity\Reservation;
use App\Enum\EventStatus;
use App\Enum\InventoryStatus;
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
 * The public ticket office — browse the published programme, pick seats,
 * hold them and pay for them (docs/WORKFLOWS.md WF-1).
 *
 * The shop is open to everyone: no account is required to browse or to see
 * the programme. An account is required to hold seats and to pay — the only
 * way in is a customer account (there is no public registration).
 *
 * The page flow and the reservation engine are shared by customers and
 * administrators. An administrator booking through the shop keeps the
 * permissions of the shop: the full tariff, the group tariff and the
 * invitation tariff may be applied; the reduced tariff is sold at the
 * counter, against a supporting document (docs/DECISIONS.md Q5).
 */
#[Route('/')]
class BookingController extends AbstractController
{
    #[Route('', name: 'booking_index', methods: ['GET'])]
    public function index(
        PerformanceRepository $performances,
        PerformancePriceRepository $prices,
        PerformancePlaceRepository $places,
        TariffRepository $tariffs,
        PerformanceLabelService $labels,
    ): Response {
        $onSale = $performances->findOnSale();
        $full = $tariffs->findOneBy(['code' => TariffCode::Full->value]);

        $ids = array_map(static fn (Performance $performance): int => (int) $performance->getId(), $onSale);
        $fromAmounts = null !== $full ? $prices->findFromAmounts($ids, $full) : [];

        $available = [];

        foreach ($onSale as $performance) {
            $available[(int) $performance->getId()] = $places->countByStatus($performance, InventoryStatus::Available);
        }

        return $this->render('booking/index.html.twig', [
            'performances' => $onSale,
            'fromAmounts' => $fromAmounts,
            'available' => $available,
            'labels' => $labels->labelsFor($onSale),
        ]);
    }


    #[Route('/events/{id}', name: 'booking_event', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function event(
        #[MapEntity(id: 'id')] \App\Entity\Event $event,
        PerformanceRepository $performances,
        PerformancePriceRepository $prices,
        PerformancePlaceRepository $places,
        TariffRepository $tariffs,
    ): Response {
        if (EventStatus::Published !== $event->getStatus()) {
            throw $this->createNotFoundException();
        }

        $shows = $performances->paginate(1, 96, $event)[0];
        $full = $tariffs->findOneBy(['code' => TariffCode::Full->value]);
        $ids = array_map(static fn (Performance $performance): int => (int) $performance->getId(), $shows);
        $fromAmounts = null !== $full ? $prices->findFromAmounts($ids, $full) : [];

        $available = [];

        foreach ($shows as $show) {
            $available[(int) $show->getId()] = $places->countByStatus($show, InventoryStatus::Available);
        }

        return $this->render('booking/event.html.twig', [
            'event' => $event,
            'performances' => $shows,
            'fromAmounts' => $fromAmounts,
            'available' => $available,
        ]);
    }

    #[Route('/events/{eventId}/performances/{performanceId}', name: 'booking_show', requirements: ['eventId' => '\\d+', 'performanceId' => '\\d+'], methods: ['GET'])]
    public function show(
        #[MapEntity(id: 'eventId')] \App\Entity\Event $event,
        #[MapEntity(id: 'performanceId')] Performance $performance,
        BookingPolicyRepository $policies,
        SeatMapViewBuilder $seatMapBuilder,
        PerformanceLabelService $labels,
    ): Response {
        $this->assertPublished($performance, $event);

        [$rows, $zoneColors, $zonePrices, $fromAmount, $availableCount, $totalCount, $zones] = $seatMapBuilder->build($performance, TariffCode::Full);

        return $this->render('booking/show.html.twig', [
            'event' => $event,
            'performance' => $performance,
            'label' => $labels->labelFor($performance),
            'rows' => $rows,
            'zones' => $zones,
            'zoneColors' => $zoneColors,
            'zonePrices' => $zonePrices,
            'fromAmount' => $fromAmount,
            'availableCount' => $availableCount,
            'totalCount' => $totalCount,
            'holdMinutes' => $policies->findOneBy([])?->getHoldDurationMinutes() ?? 10,
        ]);
    }

    #[Route('/reservations/hold/{performanceId}', name: 'booking_hold', requirements: ['performanceId' => '\\d+'], methods: ['POST'])]
    public function hold(
        Request $request,
        #[MapEntity(id: 'performanceId')] Performance $performance,
        ReservationService $reservationService,
    ): Response {
        $this->assertPublished($performance, $performance->getEvent());

        if (!$this->isCsrfTokenValid('hold_'.$performance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('booking_show', [
                'eventId' => $performance->getEvent()->getId(),
                'performanceId' => $performance->getId(),
            ]);
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw new \LogicException('The user is not signed in.');
        }

        // The online shop sells the full tariff to a customer. An administrator
        // keeps the permissions of the shop: full, group and invitation (Q5).
        $tariffCode = TariffCode::Full;

        if ($this->isGranted('ROLE_ADMIN')) {
            $choice = (string) $request->request->get('tariff', TariffCode::Full->value);

            try {
                $code = TariffCode::from($choice);

                if (TariffCode::Reduced === $code) {
                    throw new \ValueError('The reduced tariff is sold at the counter, against a supporting document.');
                }

                $tariffCode = $code;
            } catch (\ValueError | \TypeError) {
                $this->addFlash('danger', 'This tariff cannot be applied in the online shop. Please choose one of the offered tariffs.');

                return $this->redirectToRoute('booking_show', [
                    'eventId' => $performance->getEvent()->getId(),
                    'performanceId' => $performance->getId(),
                ]);
            }
        }

        $placeIds = $request->request->all('places');

        try {
            $reservation = $reservationService->hold(
                $performance,
                array_map('intval', is_array($placeIds) ? $placeIds : []),
                $tariffCode,
                SalesChannel::Online,
                $user,
                $user,
            );
        } catch (TariffNotAuthorizedException | SeatNotAvailableException | ReservationEngineException $exception) {
            $this->addFlash('warning', $exception->getMessage());

            return $this->redirectToRoute('booking_show', [
                'eventId' => $performance->getEvent()->getId(),
                'performanceId' => $performance->getId(),
            ]);
        }

        $this->addFlash('success', sprintf(
            'Seats held — complete the payment before %s.',
            $reservation->getExpiresAt()->format('d/m/Y H:i'),
        ));

        return $this->redirectToRoute('booking_reservation', ['id' => $reservation->getId()]);
    }

    #[Route('/reservations', name: 'booking_reservations', methods: ['GET'])]
    public function reservations(ReservationRepository $reservations, PerformanceLabelService $labels): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw new \LogicException('The user is not signed in.');
        }

        $own = $reservations->findForUser($user);

        return $this->render('booking/reservations.html.twig', [
            'reservations' => $own,
            'labels' => $labels->labelsFor(array_map(
                static fn (Reservation $reservation): Performance => $reservation->getPerformance(),
                $own,
            )),
        ]);
    }

    #[Route('/reservations/{id}', name: 'booking_reservation', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function reservation(
        #[MapEntity(id: 'id')] Reservation $reservation,
        \App\Repository\TicketRepository $tickets,
        PerformanceLabelService $labels,
    ): Response {
        $this->assertOwned($reservation);

        $ticketMap = [];

        foreach (ReservationStatus::Confirmed === $reservation->getStatus() ? $tickets->findForReservation($reservation) : [] as $ticket) {
            $ticketMap[$ticket->getReservationItem()->getId()] = $ticket;
        }

        return $this->render('booking/reservation.html.twig', [
            'reservation' => $reservation,
            'label' => $labels->labelFor($reservation->getPerformance()),
            'tickets' => $ticketMap,
        ]);
    }

    #[Route('/reservations/{id}/pay', name: 'booking_pay', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function pay(
        Request $request,
        #[MapEntity(id: 'id')] Reservation $reservation,
        PaymentService $paymentService,
    ): Response {
        $this->assertOwned($reservation);

        if (!$this->isCsrfTokenValid('pay_'.$reservation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('booking_reservation', ['id' => $reservation->getId()]);
        }

        if (ReservationStatus::Held !== $reservation->getStatus()) {
            $this->addFlash('warning', 'Only a held reservation can be paid.');

            return $this->redirectToRoute('booking_reservation', ['id' => $reservation->getId()]);
        }

        // The payment goes through the provider — the three outcomes of
        // docs/WORKFLOWS.md WF-1 §2.3.
        $outcome = $paymentService->pay($reservation, [
            'cardHolder' => (string) $request->request->get('cardHolder', ''),
            'cardNumber' => (string) $request->request->get('cardNumber', ''),
            'cardExpiry' => (string) $request->request->get('cardExpiry', ''),
            'cardCvc' => (string) $request->request->get('cardCvc', ''),
        ]);

        if (!$outcome->approved) {
            // BR-05 — the seats stay held until the hold expires; the
            // customer may try again.
            $this->addFlash('warning', $outcome->message.' Your seats stay held until the expiry — you can try again.');

            return $this->redirectToRoute('booking_reservation', ['id' => $reservation->getId()]);
        }

        if (ReservationStatus::Confirmed !== $reservation->getStatus()) {
            // BR-06 — a late payment is refunded in full and issues no ticket.
            $this->addFlash('warning', $outcome->message);

            return $this->redirectToRoute('booking_reservation', ['id' => $reservation->getId()]);
        }

        $this->addFlash('success', 'Payment received. Your tickets are available below.');

        return $this->redirectToRoute('booking_reservation', ['id' => $reservation->getId()]);
    }

    #[Route('/reservations/{id}/cancel', name: 'booking_cancel', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function cancel(
        Request $request,
        #[MapEntity(id: 'id')] Reservation $reservation,
        ReservationService $reservationService,
    ): Response {
        $this->assertOwned($reservation);

        if (!$this->isCsrfTokenValid('cancel_reservation_'.$reservation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('booking_reservation', ['id' => $reservation->getId()]);
        }

        try {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();
            $reservationService->cancelHeldReservation($reservation, $user);
            $this->addFlash('success', 'The reservation has been cancelled and the seats are back on sale.');
        } catch (ReservationEngineException $exception) {
            $this->addFlash('warning', $exception->getMessage());
        }

        return $this->redirectToRoute('booking_reservation', ['id' => $reservation->getId()]);
    }

    /**
     * The 404 for anything that is not on sale — a draft event has no
     * public page, and a performance of a different event is a 404 too.
     */
    private function assertPublished(Performance $performance, \App\Entity\Event $event): void
    {
        if ($performance->getEvent()->getId() !== $event->getId()) {
            throw $this->createNotFoundException();
        }

        if (EventStatus::Published !== $event->getStatus()) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * Only the account that was sold the reservation may see, pay for or
     * cancel it. An administrator is not exempt — they manage reservations
     * through the administration (Phase 9/12).
     */
    private function assertOwned(Reservation $reservation): void
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw new \LogicException('The user is not signed in.');
        }

        $isCustomer = null !== $reservation->getCustomer() && $reservation->getCustomer()->getId() === $user->getId();
        $isAuthor = $reservation->getCreatedBy()->getId() === $user->getId();

        if (!$isCustomer && !$isAuthor) {
            throw $this->createAccessDeniedException();
        }
    }

}
