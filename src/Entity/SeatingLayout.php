<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Versioned room configuration (docs/MODELE.md §C2, Q2).
 * A configuration maps existing physical seats via LayoutPlace;
 * it never creates new seats. Once used by a performance it becomes
 * unmodifiable: any evolution creates a new version.
 */
#[ORM\Entity(repositoryClass: \App\Repository\SeatingLayoutRepository::class)]
#[ORM\Table(name: 'seating_layout')]
#[ORM\UniqueConstraint(name: 'uk_seating_layout_room_version', columns: ['room_id', 'version'])]
class SeatingLayout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'seatingLayouts')]
    #[ORM\JoinColumn(nullable: false)]
    private Room $room;

    #[ORM\Column(type: 'string', length: 150)]
    private string $name;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $version;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, LayoutZone> */
    #[ORM\OneToMany(mappedBy: 'seatingLayout', targetEntity: LayoutZone::class)]
    private Collection $zones;

    /** @var Collection<int, LayoutPlace> */
    #[ORM\OneToMany(mappedBy: 'seatingLayout', targetEntity: LayoutPlace::class)]
    private Collection $layoutPlaces;

    public function __construct(Room $room, string $name, int $version, ?string $description = null, bool $isActive = true)
    {
        $this->room = $room;
        $this->name = $name;
        $this->version = $version;
        $this->description = $description;
        $this->isActive = $isActive;
        $this->createdAt = new \DateTimeImmutable();
        $this->zones = new ArrayCollection();
        $this->layoutPlaces = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoom(): Room
    {
        return $this->room;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, LayoutZone> */
    public function getZones(): Collection
    {
        return $this->zones;
    }

    /** @return Collection<int, LayoutPlace> */
    public function getLayoutPlaces(): Collection
    {
        return $this->layoutPlaces;
    }
}
