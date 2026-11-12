<?php

namespace App\Entity;

use App\Enum\PlaceType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Physical seat: exists independently of any show (docs/MODELE.md §1.1).
 * A seat has a unique code within its room (`place` UNIQUE (room_id, code)).
 */
#[ORM\Entity(repositoryClass: \App\Repository\PlaceRepository::class)]
#[ORM\Table(name: 'place')]
#[ORM\UniqueConstraint(name: 'uk_place_room_code', columns: ['room_id', 'code'])]
class Place
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'places')]
    #[ORM\JoinColumn(nullable: false)]
    private Room $room;

    #[ORM\Column(type: 'string', length: 16)]
    private string $code;

    #[ORM\Column(type: 'string', length: 20, enumType: PlaceType::class)]
    private PlaceType $type;

    #[ORM\Column(type: 'string', length: 8, nullable: true)]
    private ?string $rowLabel = null;

    #[ORM\Column(type: 'integer', nullable: true, options: ['unsigned' => true])]
    private ?int $seatNumber = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    /** @var Collection<int, LayoutPlace> */
    #[ORM\OneToMany(mappedBy: 'place', targetEntity: LayoutPlace::class)]
    private Collection $layoutPlaces;

    public function __construct(Room $room, string $code, PlaceType $type, ?string $rowLabel = null, ?int $seatNumber = null, bool $isActive = true)
    {
        $this->room = $room;
        $this->code = $code;
        $this->type = $type;
        $this->rowLabel = $rowLabel;
        $this->seatNumber = $seatNumber;
        $this->isActive = $isActive;
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getType(): PlaceType
    {
        return $this->type;
    }

    public function setType(PlaceType $type): void
    {
        $this->type = $type;
    }

    public function getRowLabel(): ?string
    {
        return $this->rowLabel;
    }

    public function setRowLabel(?string $rowLabel): void
    {
        $this->rowLabel = $rowLabel;
    }

    public function getSeatNumber(): ?int
    {
        return $this->seatNumber;
    }

    public function setSeatNumber(?int $seatNumber): void
    {
        $this->seatNumber = $seatNumber;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    /** @return Collection<int, LayoutPlace> */
    public function getLayoutPlaces(): Collection
    {
        return $this->layoutPlaces;
    }

    /**
     * Human-readable label, e.g. "A12" or "ST-001" for a standing slot.
     */
    public function getLabel(): string
    {
        if (PlaceType::StandingSlot === $this->type) {
            return $this->code;
        }

        return sprintf('%s%d', $this->rowLabel ?? '', $this->seatNumber ?? 0);
    }
}
