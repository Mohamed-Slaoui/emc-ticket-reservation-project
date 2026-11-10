<?php

namespace App\Tests\Unit;

use App\Entity\Event;
use App\Entity\LayoutPlace;
use App\Entity\LayoutZone;
use App\Entity\Payment;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Entity\PerformancePrice;
use App\Entity\Place;
use App\Entity\Refund;
use App\Entity\Reservation;
use App\Entity\ReservationItem;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Entity\Tariff;
use App\Entity\Ticket;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\EventType;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\PlaceType;
use App\Enum\RefundStatus;
use App\Enum\ReservationItemStatus;
use App\Enum\SalesChannel;
use PHPUnit\Framework\TestCase;

/**
 * Ticket lifecycle (§7.3) and payment/refund invariants (§3.3, §3.4).
 */
class TicketAndPaymentTest extends TestCase
{
    private Reservation $reservation;

    private ReservationItem $confirmedItem;

    protected function setUp(): void
    {
        $venue = new Venue('Théâtre Al-Mansour', '12, Avenue Mohammed V');
        $room = new Room($venue, 'Grande Salle');
        $layout = new SeatingLayout($room, 'Configuration Standard', 1);
        $zone = new LayoutZone($layout, 'ORC', 'Orchestre');
        $place = new Place($room, 'A-01', PlaceType::Seat, 'A', 1);
        $layoutPlace = new LayoutPlace($layout, $zone, $place, 1);
        $performance = new Performance(new Event('Hamlet', EventType::Theatre), $layout, new \DateTimeImmutable('+7 days'));
        $seat = new PerformancePlace($performance, $layoutPlace);
        $author = new User('author@demo.test', 'hash', 'A', 'B');

        $this->reservation = new Reservation($performance, $author, SalesChannel::Online, new \DateTimeImmutable('+10 minutes'));
        $this->confirmedItem = new ReservationItem(
            $this->reservation,
            $seat,
            new PerformancePrice($performance, $zone, new Tariff('PLEIN', 'Plein tarif'), '150.00'),
        );
        $this->confirmedItem->setStatus(ReservationItemStatus::Confirmed);
    }

    public function test_ticket_only_for_confirmed_item(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CONFIRMED item');

        $unconfirmed = clone $this->confirmedItem;
        $r = new \ReflectionProperty(ReservationItem::class, 'status');
        $r->setValue($unconfirmed, \App\Enum\ReservationItemStatus::Held);
        new Ticket($unconfirmed);
    }

    public function test_valid_ticket_can_be_scanned_once(): void
    {
        $ticket = new Ticket($this->confirmedItem);

        $ticket->scan();

        self::assertSame(\App\Enum\TicketStatus::Used, $ticket->getStatus());
        self::assertNotNull($ticket->getUsedAt());
    }

    public function test_double_scan_rejected(): void
    {
        $ticket = new Ticket($this->confirmedItem);
        $ticket->scan();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('double entry');
        $ticket->scan();
    }

    public function test_void_ticket_rejected_at_entry(): void
    {
        $ticket = new Ticket($this->confirmedItem);
        $ticket->void();

        self::assertSame(\App\Enum\TicketStatus::Void, $ticket->getStatus());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Void ticket rejected');
        $ticket->scan();
    }

    public function test_used_ticket_cannot_be_voided(): void
    {
        $ticket = new Ticket($this->confirmedItem);
        $ticket->scan();

        $this->expectException(\LogicException::class);
        $ticket->void();
    }

    public function test_ticket_has_unique_references(): void
    {
        $a = new Ticket($this->confirmedItem);
        $b = new Ticket($this->confirmedItem);

        self::assertStringStartsWith('TKT-', $a->getTicketNumber());
        self::assertNotSame($a->getQrToken(), $b->getQrToken());
        self::assertSame(64, \strlen($a->getQrToken()), 'Opaque 32-byte hex token (assumption A5).');
    }

    public function test_payment_starts_pending_and_transitions_once(): void
    {
        $payment = new Payment($this->reservation, '150.00', 'MAD', PaymentMethod::Cash);

        self::assertSame(PaymentStatus::Pending, $payment->getStatus());

        $payment->markSucceeded('CASH-123');

        self::assertSame(PaymentStatus::Succeeded, $payment->getStatus());
        self::assertSame('CASH-123', $payment->getProviderReference());
        self::assertNotNull($payment->getCompletedAt());

        $this->expectException(\LogicException::class);
        $payment->markFailed('late notification');
    }

    public function test_refunds_cannot_exceed_the_captured_amount(): void
    {
        $payment = new Payment($this->reservation, '150.00', 'MAD', PaymentMethod::CardTerminal);
        $payment->markSucceeded();

        // Full refund first — allowed.
        $full = new Refund($payment, '150.00', 'Annulation de la réservation');
        $payment->getRefunds()->add($full);
        $full->markSucceeded();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot exceed');
        new Refund($payment, '1.00', 'Deuxième remboursement');
    }

    public function test_refund_of_pending_payment_rejected(): void
    {
        $payment = new Payment($this->reservation, '150.00', 'MAD', PaymentMethod::Online);

        $this->expectException(\LogicException::class);
        new Refund($payment, '150.00');
    }

    public function test_refund_has_its_own_lifecycle(): void
    {
        $payment = new Payment($this->reservation, '150.00', 'MAD', PaymentMethod::Online);
        $payment->markSucceeded();
        $refund = new Refund($payment, '150.00');

        self::assertSame(RefundStatus::Pending, $refund->getStatus());

        $refund->markFailed(); // e.g. provider outage — documented (§3.4)

        self::assertSame(RefundStatus::Failed, $refund->getStatus());
        self::assertNotNull($refund->getCompletedAt(), 'The failed attempt is closed and recorded (§3.4).');
    }

    /**
     * The refund registers itself on its payment, so the cap is enforced
     * **in memory** — before any flush (§3.4). Two full refunds of a 150.00
     * payment cannot both exist.
     */
    public function test_a_second_full_refund_is_rejected_without_any_flush(): void
    {
        $payment = new Payment($this->reservation, '150.00', 'MAD', PaymentMethod::Online);
        $payment->markSucceeded();

        new Refund($payment, '150.00');
        self::assertSame('150.00', $payment->getPendingRefundAmount());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot exceed');
        new Refund($payment, '150.00');
    }

    /**
     * A pending refund already claims its share of the captured amount, so a
     * second attempt can only use what is left.
     */
    public function test_a_pending_refund_reserves_its_share_of_the_captured_amount(): void
    {
        $payment = new Payment($this->reservation, '150.00', 'MAD', PaymentMethod::Online);
        $payment->markSucceeded();

        new Refund($payment, '100.00');

        self::assertSame('100.00', $payment->getPendingRefundAmount());
        self::assertSame('0.00', $payment->getRefundedAmount(), 'A pending refund is not yet a successful refund.');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot exceed');
        new Refund($payment, '51.00');
    }

    /**
     * A failed refund releases the amount it had reserved, so a new attempt
     * becomes possible.
     */
    public function test_a_failed_refund_releases_the_reserved_amount(): void
    {
        $payment = new Payment($this->reservation, '150.00', 'MAD', PaymentMethod::Online);
        $payment->markSucceeded();

        $failed = new Refund($payment, '150.00');
        $failed->markFailed();

        self::assertSame('0.00', $payment->getPendingRefundAmount());
        self::assertSame('0.00', $payment->getCommittedRefundAmount());

        // The whole amount can be refunded again.
        $retry = new Refund($payment, '150.00');
        self::assertSame(RefundStatus::Pending, $retry->getStatus());
    }

    /**
     * The net revenue uses the **successful** refunds only (Phase 13).
     */
    public function test_successful_refunds_are_reported_separately_from_pending_ones(): void
    {
        $payment = new Payment($this->reservation, '150.00', 'MAD', PaymentMethod::Online);
        $payment->markSucceeded();

        $succeeded = new Refund($payment, '50.00');
        $succeeded->markSucceeded();
        new Refund($payment, '20.00');

        self::assertSame('50.00', $payment->getRefundedAmount());
        self::assertSame('20.00', $payment->getPendingRefundAmount());
        self::assertSame('70.00', $payment->getCommittedRefundAmount());
    }
}
