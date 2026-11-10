<?php

namespace App\Entity;

use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\RefundStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Payment attempt (docs/MODELE.md §7.2). Multiple attempts may exist for
 * one reservation; each failure is recorded. A single successful
 * transaction covers the full amount in the MVP (§3.3).
 */
#[ORM\Entity(repositoryClass: \App\Repository\PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
#[ORM\Index(name: 'idx_payment_reservation_status', columns: ['reservation_id', 'status'])]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: false)]
    private Reservation $reservation;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 20, enumType: PaymentMethod::class)]
    private PaymentMethod $method;

    #[ORM\Column(type: 'string', length: 20, enumType: PaymentStatus::class)]
    private PaymentStatus $status = PaymentStatus::Pending;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $providerReference = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, Refund> */
    #[ORM\OneToMany(mappedBy: 'payment', targetEntity: Refund::class)]
    private Collection $refunds;

    public function __construct(Reservation $reservation, string $amount, string $currency, PaymentMethod $method)
    {
        $this->reservation = $reservation;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->method = $method;
        $this->createdAt = new \DateTimeImmutable();
        $this->refunds = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getMethod(): PaymentMethod
    {
        return $this->method;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function getProviderReference(): ?string
    {
        return $this->providerReference;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    /** @return Collection<int, Refund> */
    public function getRefunds(): Collection
    {
        return $this->refunds;
    }

    /**
     * Registers a refund on this payment.
     *
     * The refund registers itself here, so the "successful refunds cannot
     * exceed the captured amount" rule is enforced **in memory** and not only
     * after a flush (docs/DECISIONS.md §3.4). Both the already-successful and
     * the still-pending refunds are counted: a pending refund is an amount
     * that is already committed to the customer.
     *
     * @throws \LogicException when the refund would exceed the captured amount
     */
    public function addRefund(Refund $refund): void
    {
        if (!$this->canBeRefunded($refund->getAmount())) {
            throw new \LogicException('The refund (including previous refunds) cannot exceed the captured amount (docs/DECISIONS.md §3.4).');
        }
        if (!$this->refunds->contains($refund)) {
            $this->refunds->add($refund);
        }
    }

    /**
     * Sum of the **successful** refunds — the amount already returned to the
     * customer. Used by the net-revenue computation (Phase 13).
     */
    public function getRefundedAmount(): string
    {
        $refunded = '0.00';
        foreach ($this->refunds as $refund) {
            if ($refund->getStatus() === RefundStatus::Succeeded) {
                $refunded = bcadd($refunded, $refund->getAmount(), 2);
            }
        }

        return $refunded;
    }

    /**
     * Sum of the **pending** refunds — attempts that are in flight at the
     * provider. They already claim their share of the captured amount, so they
     * are counted against the refund cap.
     */
    public function getPendingRefundAmount(): string
    {
        $pending = '0.00';
        foreach ($this->refunds as $refund) {
            if ($refund->getStatus() === RefundStatus::Pending) {
                $pending = bcadd($pending, $refund->getAmount(), 2);
            }
        }

        return $pending;
    }

    /**
     * Total amount already committed to refunds (successful + pending).
     */
    public function getCommittedRefundAmount(): string
    {
        return bcadd($this->getRefundedAmount(), $this->getPendingRefundAmount(), 2);
    }

    /**
     * A refund is allowed only on a successful payment, and only within the
     * captured amount — pending refunds included (docs/DECISIONS.md §3.4).
     */
    public function canBeRefunded(string $requestedAmount): bool
    {
        $committed = bcadd($this->getCommittedRefundAmount(), $requestedAmount, 2);

        return $this->status === PaymentStatus::Succeeded
            && bccomp($committed, $this->amount, 2) !== 1;
    }

    /* ---------------------------------------------------------------------
     * Transitions (PENDING → SUCCEEDED | FAILED). Terminal states refuse
     * any further transition — retried payments are NEW attempts (§3.3).
     * ------------------------------------------------------------------ */

    public function markSucceeded(?string $providerReference = null): void
    {
        if ($this->status !== PaymentStatus::Pending) {
            throw new \LogicException(sprintf('A %s payment cannot succeed again.', $this->status->value));
        }
        if ($this->amount === '0.00') {
            throw new \LogicException('A payment amount must be strictly positive.');
        }
        $this->status = PaymentStatus::Succeeded;
        $this->providerReference = $providerReference;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(?string $providerReference = null): void
    {
        if ($this->status !== PaymentStatus::Pending) {
            throw new \LogicException(sprintf('A %s payment cannot fail again.', $this->status->value));
        }
        $this->status = PaymentStatus::Failed;
        $this->providerReference = $providerReference;
        $this->completedAt = new \DateTimeImmutable();
    }
}
