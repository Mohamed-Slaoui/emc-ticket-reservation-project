<?php

namespace App\Entity;

use App\Enum\TicketStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * Admission ticket, issued after confirmation (docs/MODELE.md §6.3, INV-06):
 * at most one ticket per confirmed reservation item
 * (`ticket` UNIQUE (reservation_item_id)).
 *
 * Lifecycle (§7.3): VALID → USED | VOID; terminal states are final —
 * a ticket is NEVER reactivated; a new ticket is reissued in case of
 * cancellation followed by a new reservation.
 */
#[ORM\Entity(repositoryClass: \App\Repository\TicketRepository::class)]
#[ORM\Table(name: 'ticket')]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ReservationItem::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ReservationItem $reservationItem;

    #[ORM\Column(type: 'string', length: 32, unique: true)]
    private string $ticketNumber;

    /**
     * Opaque QR token: no personal data encoded (assumption A5).
     */
    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $qrToken;

    #[ORM\Column(type: 'string', length: 20, enumType: TicketStatus::class)]
    private TicketStatus $status = TicketStatus::Valid;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    /**
     * Issues a ticket for a confirmed item — guards INV-06 directly:
     * services must only call this for a CONFIRMED item, at most once
     * (the UNIQUE constraint on reservation_item_id backstops it).
     */
    public function __construct(ReservationItem $reservationItem)
    {
        if ($reservationItem->getStatus() !== \App\Enum\ReservationItemStatus::Confirmed) {
            throw new \LogicException('A ticket can be issued only for a CONFIRMED item (INV-06).');
        }
        $this->reservationItem = $reservationItem;
        $this->ticketNumber = self::generateTicketNumber();
        $this->qrToken = bin2hex(random_bytes(32));
        $this->issuedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservationItem(): ReservationItem
    {
        return $this->reservationItem;
    }

    public function getTicketNumber(): string
    {
        return $this->ticketNumber;
    }

    public function getQrToken(): string
    {
        return $this->qrToken;
    }

    public function getStatus(): TicketStatus
    {
        return $this->status;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    /**
     * Entry validation (docs/MODELE.md §6.4). USED and VOID tickets are
     * rejected; the atomicity of VALID → USED (two simultaneous scans)
     * is guaranteed by the service's row lock, not by the entity alone.
     */
    public function scan(): void
    {
        if ($this->status === TicketStatus::Used) {
            throw new \LogicException('Ticket already used: double entry rejected (BR-13).');
        }
        if ($this->status === TicketStatus::Void) {
            throw new \LogicException('Void ticket rejected at entry (INV-07).');
        }
        $this->status = TicketStatus::Used;
        $this->usedAt = new \DateTimeImmutable();
    }

    /**
     * Invalidate (cancellation / refund, INV-07). Terminal: never reactivated.
     */
    public function void(): void
    {
        if ($this->status === TicketStatus::Used) {
            throw new \LogicException('A used ticket cannot be voided.');
        }
        if ($this->status === TicketStatus::Void) {
            throw new \LogicException('Ticket is already void.');
        }
        $this->status = TicketStatus::Void;
    }

    private static function generateTicketNumber(): string
    {
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $suffix = '';
        for ($i = 0; $i < 10; ++$i) {
            $suffix .= $chars[random_int(0, \strlen($chars) - 1)];
        }

        return 'TKT-'.(new \DateTimeImmutable())->format('ymd').'-'.$suffix;
    }
}
