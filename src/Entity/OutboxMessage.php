<?php

namespace App\Entity;

use App\Enum\OutboxStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * Asynchronous integration event (Google Sheets synchronization, level 4).
 * Transactional outbox pattern (docs/MODELE.md §C5): written in the SAME
 * transaction as the sale, then processed by a worker. A Google Sheets
 * outage neither blocks nor cancels a sale (INV-12).
 *
 * One row per ticket (§3.7) with an idempotency key based on the ticket
 * identifier, so a re-run worker never duplicates a row.
 */
#[ORM\Entity(repositoryClass: \App\Repository\OutboxMessageRepository::class)]
#[ORM\Table(name: 'outbox_message')]
#[ORM\Index(name: 'idx_outbox_status_next_attempt', columns: ['status', 'next_attempt_at'])]
class OutboxMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /**
     * e.g. "ticket_issued", "reservation_confirmed", "reservation_cancelled".
     */
    #[ORM\Column(type: 'string', length: 64)]
    private string $eventType;

    /**
     * e.g. "Ticket" — the aggregate the payload snapshot belongs to.
     */
    #[ORM\Column(type: 'string', length: 32)]
    private string $aggregateType;

    #[ORM\Column(type: 'bigint')]
    private int $aggregateId;

    /**
     * Snapshot payload (JSON associative array).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(type: 'string', length: 20, enumType: OutboxStatus::class)]
    private OutboxStatus $status = OutboxStatus::Pending;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $attempts = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $idempotencyKey;

    #[ORM\ManyToOne(inversedBy: 'outboxMessages')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Reservation $reservation = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $eventType,
        string $aggregateType,
        int $aggregateId,
        array $payload,
        string $idempotencyKey,
        ?Reservation $reservation = null,
    ) {
        $this->eventType = $eventType;
        $this->aggregateType = $aggregateType;
        $this->aggregateId = $aggregateId;
        $this->payload = $payload;
        $this->idempotencyKey = $idempotencyKey;
        $this->reservation = $reservation;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getAggregateType(): string
    {
        return $this->aggregateType;
    }

    public function getAggregateId(): int
    {
        return $this->aggregateId;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): OutboxStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getNextAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    /* ---------------------------------------------------------------------
     * Worker transitions. All are idempotent-safe: claim() only moves
     * PENDING → PROCESSING, and a FAILED message can be retried.
     * ------------------------------------------------------------------ */

    public function claim(): void
    {
        if ($this->status !== OutboxStatus::Pending) {
            throw new \LogicException(sprintf('Cannot claim a %s outbox message.', $this->status->value));
        }
        $this->status = OutboxStatus::Processing;
        ++$this->attempts;
    }

    public function markSent(): void
    {
        if ($this->status !== OutboxStatus::Processing) {
            throw new \LogicException(sprintf('Cannot complete a %s outbox message.', $this->status->value));
        }
        $this->status = OutboxStatus::Sent;
        $this->sentAt = new \DateTimeImmutable();
        $this->nextAttemptAt = null;
    }

    public function markFailed(int $retryDelaySeconds = 30): void
    {
        if ($this->status !== OutboxStatus::Processing) {
            throw new \LogicException(sprintf('Cannot fail out a %s outbox message.', $this->status->value));
        }
        $this->status = OutboxStatus::Failed;
        $this->nextAttemptAt = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $retryDelaySeconds));
    }

    /**
     * Requeues for a new attempt — the worker picks FAILED messages whose
     * next attempt is due (retry on failure, §3.7).
     */
    public function retry(int $retryDelaySeconds = 30): void
    {
        if ($this->status !== OutboxStatus::Failed) {
            throw new \LogicException(sprintf('Cannot retry a %s outbox message.', $this->status->value));
        }
        $this->status = OutboxStatus::Pending;
        $this->nextAttemptAt = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $retryDelaySeconds));
    }
}
