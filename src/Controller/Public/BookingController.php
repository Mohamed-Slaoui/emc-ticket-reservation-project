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
    /** The palette of the zones of a configuration, in order. */
    private const ZONE_COLORS = 6;

    #[Route('', name: 'booking_index', methods: ['GET'])]
    public function index(
        PerformanceRepository $performances,
        PerformancePriceRepository $prices,
        PerformancePlaceRepository $places,
        TariffRepository $tariffs,
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
        ReservationService $reservationService,
        PerformancePriceRepository $prices,
        TariffRepository $tariffs,
        BookingPolicyRepository $policies,
    ): Response {
        $this->assertPublished($performance, $event);

        [$rows, $zoneColors, $zonePrices, $fromAmount, $availableCount, $totalCount, $zones] = $this->seatMap(
            $performance,
            $reservationService,
            $prices,
            $tariffs,
        );

        return $this->render('booking/show.html.twig', [
            'event' => $event,
            'performance' => $performance,
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
    public function reservations(ReservationRepository $reservations): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw new \LogicException('The user is not signed in.');
        }

        return $this->render('booking/reservations.html.twig', [
            'reservations' => $reservations->findForUser($user),
        ]);
    }

    #[Route('/reservations/{id}', name: 'booking_reservation', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function reservation(
        #[MapEntity(id: 'id')] Reservation $reservation,
        \App\Repository\TicketRepository $tickets,
    ): Response {
        $this->assertOwned($reservation);

        $ticketMap = [];

        foreach (ReservationStatus::Confirmed === $reservation->getStatus() ? $tickets->findForReservation($reservation) : [] as $ticket) {
            $ticketMap[$ticket->getReservationItem()->getId()] = $ticket;
        }

        return $this->render('booking/reservation.html.twig', [
            'reservation' => $reservation,
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

        if ($reservation->isExpired()) {
            $this->addFlash('warning', 'This hold has expired — the seats are back on sale.');

            return $this->redirectToRoute('booking_reservation', ['id' => $reservation->getId()]);
        }

        try {
            $paymentService->complete($reservation);
            $this->addFlash('success', 'Payment received. Your tickets are available below.');
        } catch (\LogicException) {
            $this->addFlash('warning', 'This hold can no longer be paid — it has expired.');
        }

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

    /**
     * Builds the seat map data for the template.
     *
     * @return array{0: array<string, list<array{inventoryPlace: \App\Entity\PerformancePlace, code: string, zoneId: int, status: InventoryStatus, price: string}>>, 1: array<int, int>, 2: array<int, string|null>, 3: string|null, 4: int, 5: int, 6: array<int, \App\Entity\LayoutZone>}
     */
    private function seatMap(
        Performance $performance,
        ReservationService $reservationService,
        PerformancePriceRepository $prices,
        TariffRepository $tariffs,
    ): array {
        $full = $tariffs->findOneBy(['code' => TariffCode::Full->value]);
        $tariffId = null !== $full ? (int) $full->getId() : null;

        $inventory = $reservationService->inventoryFor($performance);
        $matrix = null !== $tariffId ? $prices->findCurrentMatrix($performance) : [];

        $rows = [];
        $zones = [];

        foreach ($inventory as $entry) {
            $layoutPlace = $entry['place']->getLayoutPlace();
            $zones[(int) $entry['zone']->getId()] = $entry['zone'];
        }

        ksort($zones);

        $zoneColors = [];

        foreach (array_values($zones) as $index => $zone) {
            $zoneColors[(int) $zone->getId()] = ($index % self::ZONE_COLORS) + 1;
        }

        $availableCount = 0;

        foreach ($inventory as $entry) {
            if (InventoryStatus::Available === $entry['status']) {
                ++$availableCount;
            }

            $layoutPlace = $entry['place']->getLayoutPlace();
            $zoneId = (int) $entry['zone']->getId();
            $price = isset($matrix[$zoneId][$tariffId]) ? $matrix[$zoneId][$tariffId]->getAmount() : '0.00';
            $rowLabel = $layoutPlace->getPlace()->getRowLabel() ?? '';

            $rows[$rowLabel][] = [
                'inventoryPlace' => $entry['place'],
                'code' => $layoutPlace->getPlace()->getCode(),
                'zoneId' => $zoneId,
                'status' => $entry['status'],
                'price' => $price,
            ];
        }

        ksort($rows);

        $zonePrices = [];

        foreach ($zones as $zone) {
            $zoneId = (int) $zone->getId();
            $zonePrices[$zoneId] = isset($matrix[$zoneId][$tariffId]) ? $matrix[$zoneId][$tariffId]->getAmount() : null;
        }

        $fromAmount = null;

        if ([] !== $zonePrices) {
            $values = array_values(array_filter($zonePrices));

            if ([] !== $values) {
                $fromAmount = number_format(min(array_map('floatval', $values)), 2, '.', '');
            }
        }

        return [$rows, $zoneColors, $zonePrices, $fromAmount, $availableCount, count($inventory), $zones];
    }
}
