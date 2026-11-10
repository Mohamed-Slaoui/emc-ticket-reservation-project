<?php

namespace App\Entity;

use App\Enum\RefundStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * Refund linked to a payment, with its own lifecycle (PENDING →
 * SUCCEEDED / FAILED) — docs/MODELE.md §3.4, §7.2. Full refunds only in
 * the MVP; the total of successful refunds can never exceed the
 * captured amount.
 */
#[ORM\Entity(repositoryClass: \App\Repository\RefundRepository::class)]
#[ORM\Table(name: 'refund')]
class Refund
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'refunds')]
    #[ORM\JoinColumn(nullable: false)]
    private Payment $payment;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'string', length: 20, enumType: RefundStatus::class)]
    private RefundStatus $status = RefundStatus::Pending;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $providerReference = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(Payment $payment, string $amount, ?string $reason = null)
    {
        $this->payment = $payment;
        $this->amount = $amount;
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable();

        // Registering on the payment is what makes the refund cap enforceable
        // in memory: the payment then knows about this attempt even before the
        // flush (docs/DECISIONS.md §3.4). It throws when the cap is exceeded.
        $payment->addRefund($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPayment(): Payment
    {
        return $this->payment;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getStatus(): RefundStatus
    {
        return $this->status;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getProviderReference(): ?string
    {
        return $this->providerReference;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    /* ---------------------------------------------------------------------
     * Transitions.
     * ------------------------------------------------------------------ */

    public function markSucceeded(): void
    {
        if ($this->status !== RefundStatus::Pending) {
            throw new \LogicException(sprintf('A %s refund cannot succeed again.', $this->status->value));
        }
        $this->status = RefundStatus::Succeeded;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(): void
    {
        if ($this->status !== RefundStatus::Pending) {
            throw new \LogicException(sprintf('A %s refund cannot fail again.', $this->status->value));
        }
        $this->status = RefundStatus::Failed;
        $this->completedAt = new \DateTimeImmutable();
    }
}
