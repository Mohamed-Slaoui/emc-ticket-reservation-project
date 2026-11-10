<?php

namespace App\Entity;

use App\Enum\InventoryStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sellable inventory of a seat for a performance
 * ("seat A12 is AVAILABLE for Friday's concert").
 *
 * One inventory row per seat per performance
 * (`performance_place` UNIQUE (performance_id, layout_place_id)) —
 * this is the anti double-selling constraint (BR-01 / INV-01).
 *
 * State invariant (docs/MODELE.md §5.4):
 *
 * | status     | currentReservation | holdExpiresAt |
 * |------------|--------------------|---------------|
 * | AVAILABLE  | NULL               | NULL          |
 * | HELD       | not null           | not null      |
 * | SOLD       | not null           | NULL          |
 * | BLOCKED    | NULL               | NULL          |
 *
 * Every mutation goes through the transition methods below, which
 * guarantee this consistency at entity level. Availability is still
 * guaranteed by the database: allocation services must use a row lock
 * (SELECT … FOR UPDATE) inside the transaction (BR-02).
 */
#[ORM\Entity(repositoryClass: \App\Repository\PerformancePlaceRepository::class)]
#[ORM\Table(name: 'performance_place')]
#[ORM\UniqueConstraint(name: 'uk_performance_place_seat', columns: ['performance_id', 'layout_place_id'])]
class PerformancePlace
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'performancePlaces')]
    #[ORM\JoinColumn(nullable: false)]
    private Performance $performance;

    #[ORM\ManyToOne(inversedBy: 'performancePlaces')]
    #[ORM\JoinColumn(nullable: false)]
    private LayoutPlace $layoutPlace;

    #[ORM\Column(type: 'string', length: 20, enumType: InventoryStatus::class)]
    private InventoryStatus $status;

    #[ORM\ManyToOne(inversedBy: 'performancePlaces')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Reservation $currentReservation = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $holdExpiresAt = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $blockedReason = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, ReservationItem> */
    #[ORM\OneToMany(mappedBy: 'performancePlace', targetEntity: ReservationItem::class)]
    private Collection $reservationItems;

    /**
     * A new inventory row always starts AVAILABLE (or BLOCKED if the slot
     * must never be sold, e.g. a broken seat). Hold and sale then go
     * through hold() / markSold().
     */
    public function __construct(Performance $performance, LayoutPlace $layoutPlace, bool $blocked = false)
    {
        $this->performance = $performance;
        $this->layoutPlace = $layoutPlace;
        $this->createdAt = new \DateTimeImmutable();
        $this->reservationItems = new ArrayCollection();
        if ($blocked) {
            $this->transitionToBlocked(null);
        } else {
            $this->status = InventoryStatus::Available;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPerformance(): Performance
    {
        return $this->performance;
    }

    public function getLayoutPlace(): LayoutPlace
    {
        return $this->layoutPlace;
    }

    public function getStatus(): InventoryStatus
    {
        return $this->status;
    }

    public function getCurrentReservation(): ?Reservation
    {
        return $this->currentReservation;
    }

    public function getHoldExpiresAt(): ?\DateTimeImmutable
    {
        return $this->holdExpiresAt;
    }

    public function getBlockedReason(): ?string
    {
        return $this->blockedReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, ReservationItem> */
    public function getReservationItems(): Collection
    {
        return $this->reservationItems;
    }

    /**
     * Temporarily reserves the seat for a customer (online hold or box-office
     * pre-sale step). The seat stays held until the stored expiry (BR-04),
     * even if the booking policy changes afterwards.
     */
    public function hold(Reservation $reservation, \DateTimeImmutable $expiresAt): void
    {
        $this->assertSamePerformance($reservation);
        if ($this->status === InventoryStatus::Held) {
            throw new \LogicException('Seat is already HELD: a seat cannot be held twice (INV-02).');
        }
        if ($this->status === InventoryStatus::Sold) {
            throw new \LogicException('Seat is already SOLD: a seat is sold only once per performance (BR-01).');
        }
        if ($this->status === InventoryStatus::Blocked) {
            throw new \LogicException('A BLOCKED seat is out of sale and cannot be held.');
        }
        if ($expiresAt <= new \DateTimeImmutable('now')) {
            throw new \InvalidArgumentException('The hold expiry must lie in the future.');
        }
        $this->transitionToHeld($reservation, $expiresAt);
    }

    /**
     * Definitively sells the seat to the given reservation
     * (payment succeeded). The hold expiry, if any, is cleared.
     */
    public function markSold(Reservation $reservation): void
    {
        $this->assertSamePerformance($reservation);
        if ($this->status === InventoryStatus::Sold) {
            throw new \LogicException('Seat is already SOLD: a seat is sold only once per performance (BR-01).');
        }
        if ($this->status === InventoryStatus::Blocked) {
            throw new \LogicException('A BLOCKED seat cannot be sold.');
        }
        $this->currentReservation = $reservation;
        $this->holdExpiresAt = null;
        $this->blockedReason = null;
        $this->status = InventoryStatus::Sold;
    }

    /**
     * Releases the seat: expiry of an unpaid hold (BR-05), refund/cancel
     * (BR-08/BR-10). History is preserved — nothing is deleted (§1.3).
     *
     * A BLOCKED seat stays out of sale: only its allocation is released, its
     * status and its reason are kept (docs/MODELE.md §5.4).
     */
    public function release(): void
    {
        if ($this->status === InventoryStatus::Available) {
            throw new \LogicException('Seat is already AVAILABLE: releasing an available seat is a no-op.');
        }
        $this->currentReservation = null;
        $this->holdExpiresAt = null;
        if ($this->status === InventoryStatus::Blocked) {
            return;
        }
        $this->blockedReason = null;
        $this->status = InventoryStatus::Available;
    }

    /**
     * Removes the seat from sale (performance cancelled — Q4, seat out of
     * order…). Releases the current allocation; history is preserved.
     */
    public function block(?string $reason = null): void
    {
        if ($this->status === InventoryStatus::Blocked) {
            throw new \LogicException('Seat is already BLOCKED.');
        }
        $this->transitionToBlocked($reason);
    }

    /* ---------------------------------------------------------------------
     * Internal transitions — the only places that write the invariant trio
     * (status / currentReservation / holdExpiresAt, docs/MODELE.md §5.4).
     * ------------------------------------------------------------------ */

    private function transitionToHeld(Reservation $reservation, \DateTimeImmutable $expiresAt): void
    {
        $this->currentReservation = $reservation;
        $this->holdExpiresAt = $expiresAt;
        $this->blockedReason = null;
        $this->status = InventoryStatus::Held;
    }

    private function transitionToBlocked(?string $reason): void
    {
        $this->currentReservation = null;
        $this->holdExpiresAt = null;
        $this->blockedReason = $reason;
        $this->status = InventoryStatus::Blocked;
    }

    /**
     * A seat may only be allocated within the reservation's own performance
     * (docs/MODELE.md §5.3).
     *
     * The comparison is made on the **identifier**, not on the PHP object:
     * two distinct instances may represent the same database row (for
     * instance after an EntityManager::clear()), and comparing them by
     * identity would wrongly reject a legitimate allocation.
     */
    private function assertSamePerformance(Reservation $reservation): void
    {
        $own = $this->performance;
        $other = $reservation->getPerformance();

        $ownId = $own->getId();
        $otherId = $other->getId();

        if (null !== $ownId && null !== $otherId) {
            if ($ownId !== $otherId) {
                throw new \LogicException('A seat can only be allocated within the reservation\'s own performance (cross-cutting rule, docs/MODELE.md §5.3).');
            }

            return;
        }

        // Not persisted yet: fall back to object identity.
        if ($own !== $other) {
            throw new \LogicException('A seat can only be allocated within the reservation\'s own performance (cross-cutting rule, docs/MODELE.md §5.3).');
        }
    }
}
