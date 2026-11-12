<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\VenueRepository::class)]
#[ORM\Table(name: 'venue')]
class Venue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 150)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255)]
    private string $address;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, Room> */
    #[ORM\OneToMany(mappedBy: 'venue', targetEntity: Room::class)]
    private Collection $rooms;

    public function __construct(string $name, string $address, ?string $description = null)
    {
        $this->name = $name;
        $this->address = $address;
        $this->description = $description;
        $this->rooms = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): void
    {
        $this->address = $address;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    /** @return Collection<int, Room> */
    public function getRooms(): Collection
    {
        return $this->rooms;
    }
}
