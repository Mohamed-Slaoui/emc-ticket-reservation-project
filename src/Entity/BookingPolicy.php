<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Configurable booking rules: the temporary hold duration
 * (docs/DECISIONS.md §3.1, C7 — 10 minutes by default).
 *
 * Single-row configuration: the row records the *last* administrator who
 * changed the policy. `expiresAt` is always stored on the reservation at
 * hold creation, so changing this row never affects holds in progress (BR-04).
 */
#[ORM\Entity(repositoryClass: \App\Repository\BookingPolicyRepository::class)]
#[ORM\Table(name: 'booking_policy')]
class BookingPolicy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $holdDurationMinutes;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $updatedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $holdDurationMinutes = 10, ?User $updatedBy = null)
    {
        if ($holdDurationMinutes < 1) {
            throw new \InvalidArgumentException('The hold duration must be at least one minute.');
        }
        $this->holdDurationMinutes = $holdDurationMinutes;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHoldDurationMinutes(): int
    {
        return $this->holdDurationMinutes;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeHoldDuration(int $minutes, ?User $updatedBy): void
    {
        if ($minutes < 1) {
            throw new \InvalidArgumentException('The hold duration must be at least one minute.');
        }
        $this->holdDurationMinutes = $minutes;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
