<?php

namespace App\Tests\Unit;

use App\Entity\Event;
use App\Entity\LayoutPlace;
use App\Entity\LayoutZone;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Entity\PerformancePrice;
use App\Entity\Place;
use App\Entity\Reservation;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Entity\Tariff;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\EventType;
use App\Enum\InventoryStatus;
use App\Enum\PlaceType;
use App\Enum\ReservationItemStatus;
use App\Enum\ReservationStatus;
use App\Enum\SalesChannel;
use PHPUnit\Framework\TestCase;

/**
 * Reservation lifecycle (docs/MODELE.md §7.1) and price snapshots (Q1/C3).
 */
class ReservationTest extends TestCase
{
    private Performance $performance;

    private User $author;

    protected function setUp(): void
    {
        $venue = new Venue('Théâtre Al-Mansour', '12, Avenue Mohammed V');
        $room = new Room($venue, 'Grande Salle');
        $layout = new SeatingLayout($room, 'Configuration Standard', 1);
        $zone = new LayoutZone($layout, 'ORC', 'Orchestre');
        $place = new Place($room, 'A-01', PlaceType::Seat, 'A', 1);
        $layoutPlace = new LayoutPlace($layout, $zone, $place, 1);
        $this->performance = new Performance(
            new Event('Hamlet', EventType::Theatre),
            $layout,
            new \DateTimeImmutable('+7 days'),
        );
        $this->performancePlace = new PerformancePlace($this->performance, $layoutPlace);
        $tariff = new Tariff(\App\Enum\TariffCode::Full);
        $this->price = new PerformancePrice($this->performance, $zone, $tariff, '150.00');
        $this->author = new User('author@demo.test', 'hash', 'A', 'B');
    }

    private PerformancePlace $performancePlace;

    private PerformancePrice $price;

    private function reservation(\DateTimeImmutable $expiresAt): Reservation
    {
        return new Reservation($this->performance, $this->author, SalesChannel::Online, $expiresAt);
    }

    public function test_new_reservation_is_held_with_auto_reference(): void
    {
        $reservation = $this->reservation(new \DateTimeImmutable('+10 minutes'));

        self::assertSame(ReservationStatus::Held, $reservation->getStatus());
        self::assertStringStartsWith('RES-', $reservation->getReference());
        self::assertSame('0.00', $reservation->getTotalAmount());
    }

    public function test_item_snapshots_freeze_tariff_and_price(): void
    {
        $reservation = $this->reservation(new \DateTimeImmutable('+10 minutes'));
        $item = new \App\Entity\ReservationItem($reservation, $this->performancePlace, $this->price);

        self::assertSame('Full tariff', $item->getTariffNameSnapshot());
        self::assertSame('150.00', $item->getUnitPriceSnapshot());
        self::assertSame('150.00', $item->getLineTotalSnapshot());
        self::assertSame(ReservationItemStatus::Held, $item->getStatus());
        self::assertSame('150.00', $reservation->getTotalAmount(), 'Total amount is derived from items.');
    }

    public function test_adding_items_keeps_total_up_to_date(): void
    {
        $reservation = $this->reservation(new \DateTimeImmutable('+10 minutes'));
        $place2 = new Place($this->performance->getSeatingLayout()->getRoom(), 'A-02', PlaceType::Seat, 'A', 2);
        $layoutPlace2 = new LayoutPlace($this->performance->getSeatingLayout(), $this->price->getLayoutZone(), $place2, 2);
        $seat2 = new PerformancePlace($this->performance, $layoutPlace2);

        new \App\Entity\ReservationItem($reservation, $this->performancePlace, $this->price);
        new \App\Entity\ReservationItem($reservation, $seat2, $this->price);

        self::assertSame('300.00', $reservation->getTotalAmount());
    }

    public function test_confirm_sets_status_and_timestamp(): void
    {
        $reservation = $this->reservation(new \DateTimeImmutable('+10 minutes'));

        $reservation->confirm();

        self::assertSame(ReservationStatus::Confirmed, $reservation->getStatus());
        self::assertNotNull($reservation->getConfirmedAt());
    }

    public function test_reservation_cannot_be_confirmed_after_expiry(): void
    {
        $reservation = $this->reservation(new \DateTimeImmutable('-10 minutes'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('after its expiry');
        $reservation->confirm();
    }

    public function test_expired_reservation_is_terminal(): void
    {
        $reservation = $this->reservation(new \DateTimeImmutable('+10 minutes'));
        $reservation->expire();

        self::assertSame(ReservationStatus::Expired, $reservation->getStatus());

        $this->expectException(\LogicException::class);
        $reservation->confirm();
    }

    public function test_confirmed_reservation_can_be_cancelled(): void
    {
        $reservation = $this->reservation(new \DateTimeImmutable('+10 minutes'));
        $reservation->confirm();

        $reservation->cancel();

        self::assertSame(ReservationStatus::Cancelled, $reservation->getStatus());
        self::assertNotNull($reservation->getCancelledAt());
    }

    public function test_cancelled_reservation_is_terminal(): void
    {
        $reservation = $this->reservation(new \DateTimeImmutable('+10 minutes'));
        $reservation->cancel();

        $this->expectException(\LogicException::class);
        $reservation->confirm();
    }

    public function test_is_expired_only_for_pending_holds_past_the_deadline(): void
    {
        $expired = $this->reservation(new \DateTimeImmutable('-1 minute'));
        $active = $this->reservation(new \DateTimeImmutable('+1 hour'));
        $confirmed = $this->reservation(new \DateTimeImmutable('+1 hour'));
        $confirmed->confirm();

        self::assertTrue($expired->isExpired());
        self::assertFalse($active->isExpired());
        self::assertFalse($confirmed->isExpired());
    }
}
