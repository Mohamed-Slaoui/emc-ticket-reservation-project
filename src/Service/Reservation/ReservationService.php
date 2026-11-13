<?php

namespace App\Service\Reservation;

use App\Entity\LayoutZone;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Entity\Reservation;
use App\Entity\ReservationItem;
use App\Entity\User;
use App\Enum\InventoryStatus;
use App\Enum\ReservationItemStatus;
use App\Enum\ReservationStatus;
use App\Enum\SalesChannel;
use App\Enum\TariffCode;
use App\Exception\ReservationEngineException;
use App\Exception\SeatNotAvailableException;
use App\Exception\TariffNotAuthorizedException;
use App\Repository\BookingPolicyRepository;
use App\Repository\PerformancePriceRepository;
use App\Repository\PerformancePlaceRepository;
use App\Repository\ReservationRepository;
use App\Repository\TariffRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The booking engine — WF-1 (docs/WORKFLOWS.md).
 *
 * Holds, availability, confirmation, cancellation and expiry. The seat map
 * screen is Phase 8 (customer) and Phase 9 (box office); this is the shared
 * engine underneath them, so both channels follow the same availability rules
 * (INV-10).
 *
 * Cross-cutting invariants the engine enforces here:
 *
 * - INV-02 — a seat is never held twice: the rows are locked, then re-checked.
 * - INV-03 — a reservation cannot be confirmed after its expiry.
 * - INV-09 / Q5 — the online channel sells the full tariff only; the other
 *   tariffs go through the box office or an administrator.
 * - BR-12 — the group tariff needs at least 5 seats, all at the group rate.
 * - BR-05 — a hold that expires without payment releases its seats.
 *
 * Payment capture and tickets are deliberately **not** here — they arrive
 * with Phase 10 and Phase 11.
 */
class ReservationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReservationRepository $reservations,
        private readonly PerformancePlaceRepository $places,
        private readonly PerformancePriceRepository $prices,
        private readonly TariffRepository $tariffs,
        private readonly BookingPolicyRepository $policies,
    ) {
    }

    /**
     * The inventory of a performance — the rows the seat map renders, with
     * their zone and their sale state.
     *
     * The availability shown on screen is **advisory**. What decides is the
     * re-check under the row lock inside {@see hold()}.
     *
     * @return list<array{place: PerformancePlace, zone: LayoutZone, status: InventoryStatus}>
     */
    public function inventoryFor(Performance $performance): array
    {
        $inventory = [];

        foreach ($this->places->findForPerformance($performance) as $place) {
            $layoutPlace = $place->getLayoutPlace();

            $inventory[] = [
                'place' => $place,
                'zone' => $layoutPlace->getLayoutZone(),
                'status' => $place->getStatus(),
            ];
        }

        return $inventory;
    }

    /**
     * Holds seats for a reservation — the critical section.
     *
     * In one transaction the selected inventory rows are locked, availability
     * is re-checked, the reservation and its items are created with price
     * snapshots, and the seats are marked HELD until the booking policy's
     * expiry. The expiry is computed once, at hold creation; changing the
     * policy later does not affect holds already in progress (BR-04).
     *
     * The screen states availability as advisory; if the seats are no longer
     * available under the lock, the whole hold is rolled back.
     *
     * @param list<int>   $inventoryPlaceIds The ids of the performance_place rows.
     * @param TariffCode  $tariffCode        The chosen tariff.
     * @param SalesChannel $channel          ONLINE sells the full tariff only.
     * @param User        $createdBy         The author of the sale (the customer online, the seller at the counter).
     * @param User|null   $customer          The buyer; null for an anonymous box-office sale.
     * @param bool        $eligibilityVerified Whether the supporting document was verified in person (reduced tariff).
     *
     * @throws SeatNotAvailableException when a selected seat is no longer on sale
     * @throws TariffNotAuthorizedException when the tariff may not be applied
     * @throws ReservationEngineException when the reservation cannot be created
     */
    public function hold(
        Performance $performance,
        array $inventoryPlaceIds,
        TariffCode $tariffCode,
        SalesChannel $channel,
        User $createdBy,
        ?User $customer = null,
        bool $eligibilityVerified = false,
    ): Reservation {
        // The channel authorisation (WF-2.1 / Q5): the online channel sells
        // the full tariff only. The other tariffs are authorised at the
        // counter, where the document is verified in presence.
        if (SalesChannel::Online === $channel && TariffCode::Full !== $tariffCode) {
            throw new TariffNotAuthorizedException('Only the full tariff is sold online. Reduced, group and invitation tariffs are sold at the counter (Q5).');
        }

        if (SalesChannel::BoxOffice === $channel && TariffCode::Reduced === $tariffCode && !$eligibilityVerified) {
            throw new TariffNotAuthorizedException('The reduced tariff requires the supporting document to be verified in person, at the counter (Q5).');
        }

        return $this->entityManager->wrapInTransaction(
            function () use ($performance, $inventoryPlaceIds, $tariffCode, $channel, $createdBy, $customer, $eligibilityVerified): Reservation {
                $tariff = $this->tariffs->findOneBy(['code' => $tariffCode->value]);

                if (null === $tariff) {
                    throw new ReservationEngineException('The tariff does not exist: the catalogue is incomplete.');
                }

                $placeIds = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $inventoryPlaceIds)));

                if ([] === $placeIds) {
                    throw new ReservationEngineException('Select at least one place.');
                }

                // BR-12 — the group tariff requires at least 5 seats, all at the group rate.
                if (TariffCode::Group === $tariffCode && count($placeIds) < 5) {
                    throw new TariffNotAuthorizedException('The group tariff requires at least 5 places in the same reservation, all at the group rate (BR-12).');
                }

                // INV-02 — lock the rows first, then re-check.
                $places = $this->places->findForLockByIds($performance, $placeIds);

                if (count($places) !== count($placeIds)) {
                    throw new SeatNotAvailableException('One of the selected places does not belong to this performance.');
                }

                foreach ($places as $place) {
                    if (InventoryStatus::Available !== $place->getStatus()) {
                        throw new SeatNotAvailableException('The seat selection changed since the screen was rendered. Please choose again.');
                    }
                }

                $matrix = $this->prices->findCurrentMatrix($performance);
                $expiresAt = $this->holdExpiry();

                $reservation = new Reservation($performance, $createdBy, $channel, $expiresAt, $customer);
                $this->entityManager->persist($reservation);

                foreach ($places as $place) {
                    $zoneId = (int) $place->getLayoutPlace()->getLayoutZone()->getId();
                    $price = $matrix[$zoneId][(int) $tariff->getId()] ?? null;

                    if (null === $price) {
                        throw new ReservationEngineException('The prices of this tariff are not set for one of the zones. The performance cannot sell it yet.');
                    }

                    $item = new ReservationItem($reservation, $place, $price, $eligibilityVerified);
                    $place->hold($reservation, $expiresAt);

                    $this->entityManager->persist($item);
                }

                $this->entityManager->flush();

                return $reservation;
            },
        );
    }

    /**
     * Confirms a payment and sells the seats — WF-1 (success branch).
     *
     * The reservation, its items and the seats are moved in one transaction:
     * the reservation becomes CONFIRMED, the items CONFIRMED, the seats SOLD.
     * A reservation cannot be confirmed after its expiry (INV-03).
     *
     * The payment capture and the tickets are the caller's concern (Phase 10
     * and Phase 11).
     */
    public function confirm(Reservation $reservation): void
    {
        $this->entityManager->wrapInTransaction(function () use ($reservation): void {
            // The held rows are locked so confirmation and expiry are serialised.
            $this->places->findForLockByIds($reservation->getPerformance(), $this->placeIds($reservation));

            foreach ($this->itemPlaces($reservation) as $place) {
                if ($place->getCurrentReservation()?->getId() !== $reservation->getId()) {
                    throw new ReservationEngineException('The reservation no longer holds one of its seats.');
                }
            }

            $reservation->confirm();

            foreach ($reservation->getItems() as $item) {
                $item->setStatus(ReservationItemStatus::Confirmed);
            }

            foreach ($this->itemPlaces($reservation) as $place) {
                $place->markSold($reservation);
            }

            $this->entityManager->flush();
        });
    }

    /**
     * Releases the holds whose expiry has passed without payment (BR-05).
     *
     * The reservation moves to EXPIRED, its seats return to AVAILABLE, and
     * the items are marked EXPIRED. The `reservation_item` rows are kept —
     * history is never deleted.
     *
     * @return int How many reservations expired in this call.
     */
    public function expireDueHolds(): int
    {
        $count = 0;

        foreach ($this->reservations->findExpiredHeld() as $reservation) {
            $done = $this->entityManager->wrapInTransaction(function () use ($reservation): bool {
                // Another worker may have confirmed or cancelled it already.
                if (ReservationStatus::Held !== $reservation->getStatus()) {
                    return false;
                }

                foreach ($reservation->getItems() as $item) {
                    $item->setStatus(ReservationItemStatus::Expired);
                }

                foreach ($this->itemPlaces($reservation) as $place) {
                    $place->release();
                }

                $reservation->expire();
                $this->entityManager->flush();

                return true;
            });

            if ($done) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Cancels an unpaid hold — the owning customer, or the seller at the
     * counter.
     *
     * Only the author of the sale or the customer of the reservation may
     * cancel it while it is HELD; the seats are released, the items are
     * CANCELLED, the reservation becomes CANCELLED (BR-09). A confirmed
     * reservation is cancelled by an administrator through the refund flow —
     * not here.
     */
    public function cancelHeldReservation(Reservation $reservation, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($reservation, $actor): void {
            $isAuthor = $reservation->getCreatedBy()->getId() === $actor->getId();
            $isCustomer = null !== $reservation->getCustomer()
                && $reservation->getCustomer()->getId() === $actor->getId();

            if (!$isAuthor && !$isCustomer) {
                throw new ReservationEngineException('This hold belongs to another account.');
            }

            if (ReservationStatus::Held !== $reservation->getStatus()) {
                throw new ReservationEngineException('Only an unpaid hold can be cancelled here. A confirmed reservation is cancelled by an administrator, with a refund.');
            }

            $this->places->findForLockByIds($reservation->getPerformance(), $this->placeIds($reservation));

            foreach ($reservation->getItems() as $item) {
                $item->setStatus(ReservationItemStatus::Cancelled);
            }

            foreach ($this->itemPlaces($reservation) as $place) {
                $place->release();
            }

            $reservation->cancel();
            $this->entityManager->flush();
        });
    }

    /**
     * The hold expiry, once and for all, from the booking policy (BR-04).
     */
    private function holdExpiry(): \DateTimeImmutable
    {
        $policy = $this->policies->findOneBy([]);

        $minutes = null === $policy ? 10 : $policy->getHoldDurationMinutes();
        $minutes = max(1, $minutes);

        return (new \DateTimeImmutable())->modify(sprintf('+%d minutes', $minutes));
    }

    /**
     * The seats of a reservation, derived from its items.
     *
     * @return list<PerformancePlace>
     */
    private function itemPlaces(Reservation $reservation): array
    {
        $places = [];

        foreach ($reservation->getItems() as $item) {
            $places[] = $item->getPerformancePlace();
        }

        return $places;
    }

    /**
     * @return list<int>
     */
    private function placeIds(Reservation $reservation): array
    {
        return array_map(
            static fn (PerformancePlace $place): int => (int) $place->getId(),
            $this->itemPlaces($reservation),
        );
    }
}
