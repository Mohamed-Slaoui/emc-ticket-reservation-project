<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Associates a physical seat with a zone within a given configuration.
 * A seat appears only once per configuration
 * (`layout_place` UNIQUE (seating_layout_id, place_id)).
 */
#[ORM\Entity(repositoryClass: \App\Repository\LayoutPlaceRepository::class)]
#[ORM\Table(name: 'layout_place')]
#[ORM\UniqueConstraint(name: 'uk_layout_place_layout_seat', columns: ['seating_layout_id', 'place_id'])]
#[ORM\Index(name: 'idx_layout_place_zone', columns: ['layout_zone_id'])]
class LayoutPlace
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'layoutPlaces')]
    #[ORM\JoinColumn(nullable: false)]
    private SeatingLayout $seatingLayout;

    #[ORM\ManyToOne(inversedBy: 'layoutPlaces')]
    #[ORM\JoinColumn(nullable: false)]
    private LayoutZone $layoutZone;

    #[ORM\ManyToOne(inversedBy: 'layoutPlaces')]
    #[ORM\JoinColumn(nullable: false)]
    private Place $place;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $displayOrder;

    #[ORM\Column(type: 'boolean')]
    private bool $isAvailableInLayout = true;

    /** @var Collection<int, PerformancePlace> */
    #[ORM\OneToMany(mappedBy: 'layoutPlace', targetEntity: PerformancePlace::class)]
    private Collection $performancePlaces;

    public function __construct(SeatingLayout $seatingLayout, LayoutZone $layoutZone, Place $place, int $displayOrder, bool $isAvailableInLayout = true)
    {
        $this->seatingLayout = $seatingLayout;
        $this->layoutZone = $layoutZone;
        $this->place = $place;
        $this->displayOrder = $displayOrder;
        $this->isAvailableInLayout = $isAvailableInLayout;
        $this->performancePlaces = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeatingLayout(): SeatingLayout
    {
        return $this->seatingLayout;
    }

    public function getLayoutZone(): LayoutZone
    {
        return $this->layoutZone;
    }

    public function getPlace(): Place
    {
        return $this->place;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): void
    {
        $this->displayOrder = $displayOrder;
    }

    public function isAvailableInLayout(): bool
    {
        return $this->isAvailableInLayout;
    }

    public function setIsAvailableInLayout(bool $isAvailableInLayout): void
    {
        $this->isAvailableInLayout = $isAvailableInLayout;
    }

    /**
     * Moves the seat to another zone of the same configuration.
     */
    public function moveToZone(LayoutZone $zone): void
    {
        $this->layoutZone = $zone;
    }

    /** @return Collection<int, PerformancePlace> */
    public function getPerformancePlaces(): Collection
    {
        return $this->performancePlaces;
    }
}
