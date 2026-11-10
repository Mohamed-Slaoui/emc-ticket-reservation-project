<?php

namespace App\Entity;

use App\Enum\ReservationItemStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * One reserved seat, with a snapshot of the tariff and the price at
 * reservation time (docs/MODELE.md Q1, C3): the price of an already-sold
 * ticket never changes if the tariff is modified afterwards (INV-05).
 *
 * History is preserved: a seat can go through several items over time
 * (`reservation_item` UNIQUE (reservation_id, performance_place_id) only
 * prevents the same seat appearing twice *in one reservation*).
 */
#[ORM\Entity(repositoryClass: \App\Repository\ReservationItemRepository::class)]
#[ORM\Table(name: 'reservation_item')]
#[ORM\UniqueConstraint(name: 'uk_reservation_item_seat', columns: ['reservation_id', 'performance_place_id'])]
class ReservationItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private Reservation $reservation;

    #[ORM\ManyToOne(inversedBy: 'reservationItems')]
    #[ORM\JoinColumn(nullable: false)]
    private PerformancePlace $performancePlace;

    #[ORM\ManyToOne(targetEntity: PerformancePrice::class)]
    #[ORM\JoinColumn(nullable: false)]
    private PerformancePrice $performancePrice;

    #[ORM\Column(type: 'string', length: 100)]
    private string $tariffNameSnapshot;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $unitPriceSnapshot;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $lineTotalSnapshot;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currencySnapshot;

    #[ORM\Column(type: 'string', length: 20, enumType: ReservationItemStatus::class)]
    private ReservationItemStatus $status;

    #[ORM\Column(type: 'boolean')]
    private bool $eligibilityVerified = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * The constructor freezes the price snapshot: unit price from the
     * current PerformancePrice, line total = unit price (quantity is
     * always 1 — one item = one seat).
     */
    public function __construct(
        Reservation $reservation,
        PerformancePlace $performancePlace,
        PerformancePrice $performancePrice,
        bool $eligibilityVerified = false,
    ) {
        $this->reservation = $reservation;
        $this->performancePlace = $performancePlace;
        $this->performancePrice = $performancePrice;
        $this->tariffNameSnapshot = $performancePrice->getTariff()->getName();
        $this->unitPriceSnapshot = $performancePrice->getAmount();
        $this->lineTotalSnapshot = $performancePrice->getAmount();
        $this->currencySnapshot = $performancePrice->getCurrency();
        $this->status = ReservationItemStatus::Held;
        $this->eligibilityVerified = $eligibilityVerified;
        $this->createdAt = new \DateTimeImmutable();
        $reservation->addItem($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getPerformancePlace(): PerformancePlace
    {
        return $this->performancePlace;
    }

    public function getPerformancePrice(): PerformancePrice
    {
        return $this->performancePrice;
    }

    public function getTariffNameSnapshot(): string
    {
        return $this->tariffNameSnapshot;
    }

    public function getUnitPriceSnapshot(): string
    {
        return $this->unitPriceSnapshot;
    }

    public function getLineTotalSnapshot(): string
    {
        return $this->lineTotalSnapshot;
    }

    public function getCurrencySnapshot(): string
    {
        return $this->currencySnapshot;
    }

    public function getStatus(): ReservationItemStatus
    {
        return $this->status;
    }

    public function isEligibilityVerified(): bool
    {
        return $this->eligibilityVerified;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Items follow their reservation's lifecycle (§4.4): no independent
     * transitions — the owning services mirror the reservation status here.
     */
    public function setStatus(ReservationItemStatus $status): void
    {
        $this->status = $status;
    }
}
