<?php

namespace App\Service\Event;

use App\Entity\Event;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Enum\PerformanceStatus;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Exception\InvalidPerformanceScheduleException;
use App\Exception\PerformanceCannotBeDeletedException;
use App\Exception\PerformanceRoomUnavailableException;
use App\Repository\LayoutPlaceRepository;
use App\Repository\PerformancePlaceRepository;
use App\Repository\PerformanceRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The performances: the dated shows of an event.
 *
 * A performance uses **exactly one** seating configuration, and that
 * configuration generates its **inventory**: one sellable seat per seat of
 * the configuration.
 *
 * The configuration is frozen from that moment on — it belongs to the past
 * sales.
 *
 * The **room** is reached through the configuration, and it is the room that
 * is booked: a room cannot host two overlapping performances, whichever
 * configuration they use.
 */
class PerformanceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PerformanceRepository $performances,
        private readonly PerformancePlaceRepository $performancePlaces,
        private readonly LayoutPlaceRepository $layoutPlaces,
    ) {
    }

    /**
     * Schedules a performance and generates its inventory.
     *
     * @throws InvalidPerformanceScheduleException when the dates are not coherent
     * @throws PerformanceRoomUnavailableException when the room is already occupied
     */
    public function create(
        Event $event,
        SeatingLayout $layout,
        \DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt = null,
        ?\DateTimeImmutable $salesOpenAt = null,
        ?\DateTimeImmutable $salesCloseAt = null,
    ): Performance {
        $this->assertScheduleIsCoherent($startsAt, $endsAt, $salesCloseAt);

        return $this->entityManager->wrapInTransaction(
            function () use ($event, $layout, $startsAt, $endsAt, $salesOpenAt, $salesCloseAt): Performance {
                // The room is locked **before** the conflict is checked: two
                // administrators scheduling the same room at the same instant
                // are serialised, and the second one sees the first show.
                $room = $this->lockRoom($layout->getRoom());

                $this->assertRoomIsFree($room, $startsAt, $endsAt);

                $performance = new Performance($event, $layout, $startsAt, $endsAt);

                $performance->setSalesOpenAt($salesOpenAt);
                $performance->setSalesCloseAt($salesCloseAt);

                $this->entityManager->persist($performance);
                $this->entityManager->flush();

                $this->generateInventory($performance);

                return $performance;
            },
        );
    }

    /**
     * @throws InvalidPerformanceScheduleException when the dates are not coherent
     * @throws PerformanceRoomUnavailableException when the room is already occupied
     */
    public function update(
        Performance $performance,
        \DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
        ?\DateTimeImmutable $salesOpenAt,
        ?\DateTimeImmutable $salesCloseAt,
    ): void {
        $this->assertScheduleIsCoherent($startsAt, $endsAt, $salesCloseAt);

        $this->entityManager->wrapInTransaction(function () use ($performance, $startsAt, $endsAt, $salesOpenAt, $salesCloseAt): void {
            $room = $this->lockRoom($performance->getSeatingLayout()->getRoom());

            // The performance does not conflict with itself.
            $this->assertRoomIsFree($room, $startsAt, $endsAt, $performance);

            $performance->setStartsAt($startsAt);
            $performance->setEndsAt($endsAt);
            $performance->setSalesOpenAt($salesOpenAt);
            $performance->setSalesCloseAt($salesCloseAt);

            $this->entityManager->flush();
        });
    }

    /**
     * Cancels the performance: it stops being sold, and its history stays.
     */
    public function cancel(Performance $performance): void
    {
        $performance->setStatus(PerformanceStatus::Cancelled);

        $this->entityManager->flush();
    }

    /**
     * Deletes the performance and its inventory.
     *
     * @throws PerformanceCannotBeDeletedException when the performance already holds a sale
     */
    public function delete(Performance $performance): void
    {
        if ($performance->getReservations()->count() > 0) {
            throw new PerformanceCannotBeDeletedException(
                'This performance already holds a reservation: the sale must survive. Cancel the performance instead of deleting it.',
            );
        }

        // The inventory goes with it.
        foreach ($this->performancePlaces->findForPerformance($performance) as $performancePlace) {
            $this->entityManager->remove($performancePlace);
        }

        $this->entityManager->remove($performance);
        $this->entityManager->flush();
    }

    /**
     * Generates the inventory of the performance: one sellable seat per seat
     * of its configuration.
     *
     * A seat that the configuration marks as unavailable is **blocked** — it
     * is not on sale, but it stays in the configuration.
     */
    private function generateInventory(Performance $performance): void
    {
        foreach ($this->layoutPlaces->findForLayout($performance->getSeatingLayout()) as $layoutPlace) {
            $this->entityManager->persist(new PerformancePlace(
                $performance,
                $layoutPlace,
                !$layoutPlace->isAvailableInLayout(),
            ));
        }

        $this->entityManager->flush();
    }

    /**
     * Locks the room row for the rest of the transaction.
     *
     * The lock is the guarantee: every scheduling of that room goes through
     * it, so two concurrent requests cannot both pass the conflict check.
     */
    private function lockRoom(Room $room): Room
    {
        $locked = $this->entityManager->find(Room::class, $room->getId(), LockMode::PESSIMISTIC_WRITE);

        return $locked instanceof Room ? $locked : $room;
    }

    /**
     * @throws PerformanceRoomUnavailableException when another show occupies the room
     */
    private function assertRoomIsFree(
        Room $room,
        \DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
        ?Performance $exclude = null,
    ): void {
        $conflicts = $this->performances->findOverlappingInRoom($room, $startsAt, $endsAt, $exclude);

        if ([] === $conflicts) {
            return;
        }

        $conflict = $conflicts[0];

        throw new PerformanceRoomUnavailableException(sprintf(
            '%s is already occupied by "%s" from %s to %s. This performance cannot be scheduled during that interval.',
            $room->getName(),
            $conflict->getEvent()->getTitle(),
            $conflict->getStartsAt()->format('d/m/Y H:i'),
            $conflict->getEndsAt()?->format('d/m/Y H:i') ?? 'no end date',
        ));
    }

    /**
     * The schedule of a show has to make sense.
     *
     * @throws InvalidPerformanceScheduleException
     */
    private function assertScheduleIsCoherent(
        \DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
        ?\DateTimeImmutable $salesCloseAt,
    ): void {
        if (null !== $endsAt && $endsAt <= $startsAt) {
            throw new InvalidPerformanceScheduleException(sprintf(
                'The show ends on %s, before it starts on %s: when it crosses midnight, choose the next day explicitly.',
                $endsAt->format('d/m/Y H:i'),
                $startsAt->format('d/m/Y H:i'),
            ));
        }

        if (null !== $salesCloseAt && $salesCloseAt > $startsAt) {
            throw new InvalidPerformanceScheduleException(sprintf(
                'The sale closes on %s, after the show starts on %s: the tickets must stop being sold when the show begins.',
                $salesCloseAt->format('d/m/Y H:i'),
                $startsAt->format('d/m/Y H:i'),
            ));
        }
    }
}
