<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\RoomRepository::class)]
#[ORM\Table(name: 'room')]
class Room
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'rooms')]
    #[ORM\JoinColumn(nullable: false)]
    private Venue $venue;

    #[ORM\Column(type: 'string', length: 150)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, Place> */
    #[ORM\OneToMany(mappedBy: 'room', targetEntity: Place::class)]
    private Collection $places;

    /** @var Collection<int, SeatingLayout> */
    #[ORM\OneToMany(mappedBy: 'room', targetEntity: SeatingLayout::class)]
    private Collection $seatingLayouts;

    public function __construct(Venue $venue, string $name, ?string $description = null)
    {
        $this->venue = $venue;
        $this->name = $name;
        $this->description = $description;
        $this->places = new ArrayCollection();
        $this->seatingLayouts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVenue(): Venue
    {
        return $this->venue;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /** @return Collection<int, Place> */
    public function getPlaces(): Collection
    {
        return $this->places;
    }

    /** @return Collection<int, SeatingLayout> */
    public function getSeatingLayouts(): Collection
    {
        return $this->seatingLayouts;
    }
}
