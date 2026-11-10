<?php

namespace App\Entity;

use App\Enum\ReservationStatus;
use App\Enum\SalesChannel;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Reservation: the single aggregate of the purchase journey
 * (docs/MODELE.md §C2). Covers exactly ONE performance (§C6) and moves
 * through the hold → payment → confirmation lifecycle (§7.1).
 */
#[ORM\Entity(repositoryClass: \App\Repository\ReservationRepository::class)]
#[ORM\Table(name: 'reservation')]
#[ORM\Index(name: 'idx_reservation_status_expires', columns: ['status', 'expires_at'])]
class Reservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    #[ORM\JoinColumn(nullable: false)]
    private Performance $performance;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $customer = null;

    #[ORM\Column(type: 'string', length: 32, unique: true)]
    private string $reference;

    #[ORM\Column(type: 'string', length: 20, enumType: ReservationStatus::class)]
    private ReservationStatus $status = ReservationStatus::Held;

    #[ORM\Column(type: 'string', length: 20, enumType: SalesChannel::class)]
    private SalesChannel $channel;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalAmount = '0.00';

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency = 'MAD';

    /**
     * Stored at hold creation — changing the booking policy never
     * affects holds in progress (BR-04).
     */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /** @var Collection<int, PerformancePlace> */
    #[ORM\OneToMany(mappedBy: 'currentReservation', targetEntity: PerformancePlace::class)]
    private Collection $performancePlaces;

    /** @var Collection<int, ReservationItem> */
    #[ORM\OneToMany(mappedBy: 'reservation', targetEntity: ReservationItem::class)]
    private Collection $items;

    /** @var Collection<int, Payment> */
    #[ORM\OneToMany(mappedBy: 'reservation', targetEntity: Payment::class)]
    private Collection $payments;

    /** @var Collection<int, OutboxMessage> */
    #[ORM\OneToMany(mappedBy: 'reservation', targetEntity: OutboxMessage::class)]
    private Collection $outboxMessages;

    /**
     * @param SalesChannel $channel ONLINE for the web, BOX_OFFICE at the counter.
     * @param User|null    $customer Optional (anonymous box-office sale);
     *                               the author ($createdBy) is always recorded (§3.8).
     */
    public function __construct(
        Performance $performance,
        User $createdBy,
        SalesChannel $channel,
        \DateTimeImmutable $expiresAt,
        ?User $customer = null,
        ?string $reference = null,
    ) {
        $this->performance = $performance;
        $this->createdBy = $createdBy;
        $this->customer = $customer;
        $this->channel = $channel;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->reference = $reference ?? self::generateReference();
        $this->performancePlaces = new ArrayCollection();
        $this->items = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $this->outboxMessages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPerformance(): Performance
    {
        return $this->performance;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCustomer(): ?User
    {
        return $this->customer;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getStatus(): ReservationStatus
    {
        return $this->status;
    }

    public function getChannel(): SalesChannel
    {
        return $this->channel;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    /** @return Collection<int, ReservationItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /** @return Collection<int, Payment> */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    /** @return Collection<int, OutboxMessage> */
    public function getOutboxMessages(): Collection
    {
        return $this->outboxMessages;
    }

    /**
     * Adds an item and keeps the total amount as the sum of line totals
     * (the total is derived data, never set independently).
     */
    public function addItem(ReservationItem $item): void
    {
        if ($this->items->contains($item)) {
            return;
        }
        $this->items->add($item);
        $this->totalAmount = bcadd($this->totalAmount, $item->getLineTotalSnapshot(), 2);
    }

    /* ---------------------------------------------------------------------
     * Lifecycle transitions (docs/MODELE.md §7.1). Multi-entity coordination
     * (inventory release, refunds, tickets) lives in the responsible services;
     * these methods only guard the reservation's own state machine.
     * ------------------------------------------------------------------ */

    public function confirm(): void
    {
        $this->assertTransitionAllowed([ReservationStatus::Held]);
        if ($this->expiresAt <= new \DateTimeImmutable('now')) {
            throw new \LogicException('A reservation cannot be confirmed after its expiry (INV-03).');
        }
        $this->status = ReservationStatus::Confirmed;
        $this->confirmedAt = new \DateTimeImmutable();
    }

    public function expire(): void
    {
        $this->assertTransitionAllowed([ReservationStatus::Held]);
        $this->status = ReservationStatus::Expired;
    }

    public function cancel(): void
    {
        $this->assertTransitionAllowed([ReservationStatus::Held, ReservationStatus::Confirmed]);
        $this->status = ReservationStatus::Cancelled;
        $this->cancelledAt = new \DateTimeImmutable();
    }

    public function isExpired(): bool
    {
        return $this->status === ReservationStatus::Held && $this->expiresAt <= new \DateTimeImmutable('now');
    }

    private function assertTransitionAllowed(array $from): void
    {
        if (!\in_array($this->status, $from, true)) {
            throw new \LogicException(sprintf(
                'Invalid reservation transition: %s is terminal or incompatible (docs/MODELE.md §7.1).',
                $this->status->value,
            ));
        }
    }

    private static function generateReference(): string
    {
        return sprintf('RES-%s-%s', (new \DateTimeImmutable())->format('ymd'), strtoupper(bin2hex(random_bytes(4))));
    }
}
