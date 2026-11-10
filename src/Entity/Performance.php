<?php

namespace App\Entity;

use App\Enum\PerformanceStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dated performance of an event. It references exactly one versioned
 * SeatingLayout (docs/MODELE.md Q2) and generates its inventory of PerformancePlace.
 */
#[ORM\Entity(repositoryClass: \App\Repository\PerformanceRepository::class)]
#[ORM\Table(name: 'performance')]
#[ORM\Index(name: 'idx_performance_starts_at', columns: ['starts_at'])]
class Performance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'performances')]
    #[ORM\JoinColumn(nullable: false)]
    private Event $event;

    #[ORM\ManyToOne(targetEntity: SeatingLayout::class)]
    #[ORM\JoinColumn(nullable: false)]
    private SeatingLayout $seatingLayout;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $salesOpenAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $salesCloseAt = null;

    #[ORM\Column(type: 'string', length: 20, enumType: PerformanceStatus::class)]
    private PerformanceStatus $status = PerformanceStatus::Scheduled;

    /** @var Collection<int, PerformancePlace> */
    #[ORM\OneToMany(mappedBy: 'performance', targetEntity: PerformancePlace::class)]
    private Collection $performancePlaces;

    /** @var Collection<int, PerformancePrice> */
    #[ORM\OneToMany(mappedBy: 'performance', targetEntity: PerformancePrice::class)]
    private Collection $performancePrices;

    /** @var Collection<int, Reservation> */
    #[ORM\OneToMany(mappedBy: 'performance', targetEntity: Reservation::class)]
    private Collection $reservations;

    public function __construct(Event $event, SeatingLayout $seatingLayout, \DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt = null)
    {
        $this->event = $event;
        $this->seatingLayout = $seatingLayout;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->performancePlaces = new ArrayCollection();
        $this->performancePrices = new ArrayCollection();
        $this->reservations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getSeatingLayout(): SeatingLayout
    {
        return $this->seatingLayout;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getSalesOpenAt(): ?\DateTimeImmutable
    {
        return $this->salesOpenAt;
    }

    public function setSalesOpenAt(?\DateTimeImmutable $salesOpenAt): void
    {
        $this->salesOpenAt = $salesOpenAt;
    }

    public function getSalesCloseAt(): ?\DateTimeImmutable
    {
        return $this->salesCloseAt;
    }

    public function setSalesCloseAt(?\DateTimeImmutable $salesCloseAt): void
    {
        $this->salesCloseAt = $salesCloseAt;
    }

    public function getStatus(): PerformanceStatus
    {
        return $this->status;
    }

    /** @return Collection<int, PerformancePlace> */
    public function getPerformancePlaces(): Collection
    {
        return $this->performancePlaces;
    }

    /** @return Collection<int, PerformancePrice> */
    public function getPerformancePrices(): Collection
    {
        return $this->performancePrices;
    }

    /** @return Collection<int, Reservation> */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }
}
