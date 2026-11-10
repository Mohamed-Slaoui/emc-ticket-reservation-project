<?php

namespace App\Tests\Unit;

use App\Entity\Event;
use App\Entity\LayoutPlace;
use App\Entity\LayoutZone;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Entity\Place;
use App\Entity\Reservation;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\InventoryStatus;
use App\Enum\PlaceType;
use App\Enum\ReservationStatus;
use App\Enum\SalesChannel;
use PHPUnit\Framework\TestCase;

/**
 * Entity-level state invariant of PerformancePlace (docs/MODELE.md §5.4):
 * no service, no dispatcher — the entity owns its states.
 */
class PerformancePlaceTest extends TestCase
{
    private Performance $performance;

    private PerformancePlace $seat;

    protected function setUp(): void
    {
        $venue = new Venue('Théâtre Al-Mansour', '12, Avenue Mohammed V');
        $room = new Room($venue, 'Grande Salle');
        $layout = new SeatingLayout($room, 'Configuration Standard', 1);
        $event = new Event('Hamlet', \App\Enum\EventType::Theatre);
        $zone = new LayoutZone($layout, 'ORC', 'Orchestre');
        $place = new Place($room, 'A-01', PlaceType::Seat, 'A', 1);
        $layoutPlace = new LayoutPlace($layout, $zone, $place, 1);
        $this->performance = new Performance($event, $layout, new \DateTimeImmutable('+7 days'));
        $this->seat = new PerformancePlace($this->performance, $layoutPlace);

        // A usable author for reservations.
        $this->author = new User('author@demo.test', 'hash', 'A', 'B');
    }

    private User $author;

    private function reservation(): Reservation
    {
        return new Reservation(
            $this->performance,
            $this->author,
            SalesChannel::BoxOffice,
            new \DateTimeImmutable('+10 minutes'),
        );
    }

    public function test_new_inventory_row_starts_available(): void
    {
        self::assertSame(InventoryStatus::Available, $this->seat->getStatus());
        self::assertNull($this->seat->getCurrentReservation());
        self::assertNull($this->seat->getHoldExpiresAt());
    }

    public function test_hold_sets_status_reservation_and_expiry(): void
    {
        $reservation = $this->reservation();
        $expiresAt = new \DateTimeImmutable('+10 minutes');

        $this->seat->hold($reservation, $expiresAt);

        self::assertSame(InventoryStatus::Held, $this->seat->getStatus());
        self::assertSame($reservation, $this->seat->getCurrentReservation());
        self::assertSame($expiresAt, $this->seat->getHoldExpiresAt());
    }

    public function test_seat_cannot_be_held_twice(): void
    {
        $this->seat->hold($this->reservation(), new \DateTimeImmutable('+10 minutes'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('held twice');
        $this->seat->hold($this->reservation(), new \DateTimeImmutable('+15 minutes'));
    }

    public function test_sold_seat_cannot_be_held(): void
    {
        $reservation = $this->reservation();
        $this->seat->hold($reservation, new \DateTimeImmutable('+10 minutes'));
        $this->seat->markSold($reservation);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('sold only once');
        $this->seat->hold($this->reservation(), new \DateTimeImmutable('+10 minutes'));
    }

    public function test_mark_sold_sets_sold_and_clears_expiry(): void
    {
        $reservation = $this->reservation();
        $this->seat->hold($reservation, new \DateTimeImmutable('+10 minutes'));

        $this->seat->markSold($reservation);

        self::assertSame(InventoryStatus::Sold, $this->seat->getStatus());
        self::assertSame($reservation, $this->seat->getCurrentReservation());
        self::assertNull($this->seat->getHoldExpiresAt(), 'A SOLD seat has no hold expiry.');
    }

    public function test_release_resets_the_state_trio(): void
    {
        $reservation = $this->reservation();
        $this->seat->hold($reservation, new \DateTimeImmutable('+10 minutes'));
        $this->seat->markSold($reservation);

        $this->seat->release();

        self::assertSame(InventoryStatus::Available, $this->seat->getStatus());
        self::assertNull($this->seat->getCurrentReservation());
        self::assertNull($this->seat->getHoldExpiresAt());
    }

    public function test_block_removes_seat_from_sale(): void
    {
        $this->seat->block('Siège endommagé');

        self::assertSame(InventoryStatus::Blocked, $this->seat->getStatus());
        self::assertNull($this->seat->getCurrentReservation());
        self::assertNull($this->seat->getHoldExpiresAt());
        self::assertSame('Siège endommagé', $this->seat->getBlockedReason());
    }

    public function test_seat_refuses_reservation_from_another_performance(): void
    {
        $otherEvent = new Event('Autre spectacle', \App\Enum\EventType::Concert, status: \App\Enum\EventStatus::Published);
        $otherPerformance = new Performance($otherEvent, $this->performance->getSeatingLayout(), new \DateTimeImmutable('+30 days'));
        $wrongReservation = new Reservation(
            $otherPerformance,
            $this->author,
            SalesChannel::Online,
            new \DateTimeImmutable('+10 minutes'),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('own performance');
        $this->seat->hold($wrongReservation, new \DateTimeImmutable('+10 minutes'));
    }

    public function test_hold_expiry_must_be_in_the_future(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->seat->hold($this->reservation(), new \DateTimeImmutable('-10 minutes'));
    }

    /**
     * Two distinct PHP objects may represent the **same** database row (for
     * instance after an EntityManager::clear(), or when a service re-reads
     * the performance). Comparing them by object identity would wrongly
     * reject a legitimate allocation, so the comparison is made on the
     * identifier (docs/MODELE.md §5.3).
     */
    public function test_hold_accepts_another_instance_of_the_same_persisted_performance(): void
    {
        $reflection = new \ReflectionProperty(Performance::class, 'id');
        $reflection->setValue($this->performance, 42);

        // A second instance of the same row: same id, different object.
        $sameRow = clone $this->performance;

        $reservation = new Reservation(
            $sameRow,
            $this->author,
            SalesChannel::Online,
            new \DateTimeImmutable('+10 minutes'),
        );

        $this->seat->hold($reservation, new \DateTimeImmutable('+10 minutes'));

        self::assertSame(InventoryStatus::Held, $this->seat->getStatus());
        self::assertSame($reservation, $this->seat->getCurrentReservation());
    }

    /**
     * The state invariant of docs/MODELE.md §5.4, for every status.
     */
    public function test_state_invariant_holds_for_every_status(): void
    {
        $reservation = $this->reservation();

        // AVAILABLE: no current reservation, no expiry.
        self::assertSame(InventoryStatus::Available, $this->seat->getStatus());
        self::assertNull($this->seat->getCurrentReservation());
        self::assertNull($this->seat->getHoldExpiresAt());

        // HELD: a current reservation AND an expiry.
        $this->seat->hold($reservation, new \DateTimeImmutable('+10 minutes'));
        self::assertSame(InventoryStatus::Held, $this->seat->getStatus());
        self::assertNotNull($this->seat->getCurrentReservation());
        self::assertNotNull($this->seat->getHoldExpiresAt());

        // SOLD: a current reservation, no expiry.
        $this->seat->markSold($reservation);
        self::assertSame(InventoryStatus::Sold, $this->seat->getStatus());
        self::assertNotNull($this->seat->getCurrentReservation());
        self::assertNull($this->seat->getHoldExpiresAt());

        // BLOCKED: no current reservation, no expiry.
        $this->seat->release();
        $this->seat->block('Siège endommagé');
        self::assertSame(InventoryStatus::Blocked, $this->seat->getStatus());
        self::assertNull($this->seat->getCurrentReservation());
        self::assertNull($this->seat->getHoldExpiresAt());
    }

    /**
     * A BLOCKED seat is out of sale: it can neither be held nor sold, and
     * releasing it does not put it back on sale.
     */
    public function test_a_blocked_seat_cannot_be_held_or_sold(): void
    {
        $this->seat->block('Siège endommagé');
        $reservation = $this->reservation();

        try {
            $this->seat->hold($reservation, new \DateTimeImmutable('+10 minutes'));
            self::fail('A blocked seat must not be holdable.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('BLOCKED', $e->getMessage());
        }

        self::assertSame(InventoryStatus::Blocked, $this->seat->getStatus());
        self::assertNull($this->seat->getCurrentReservation());
    }

    /**
     * Releasing a blocked seat frees its allocation but keeps it out of sale:
     * BLOCKED must never silently become AVAILABLE.
     */
    public function test_releasing_a_blocked_seat_keeps_it_out_of_sale(): void
    {
        $this->seat->block('Siège endommagé');

        $this->seat->release();

        self::assertSame(InventoryStatus::Blocked, $this->seat->getStatus());
        self::assertNull($this->seat->getCurrentReservation());
        self::assertNull($this->seat->getHoldExpiresAt());
        self::assertSame('Siège endommagé', $this->seat->getBlockedReason());
    }
}
