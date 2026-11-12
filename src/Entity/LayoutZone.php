<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Zone / category within a configuration (e.g. "Orchestre", "Mezzanine").
 * A zone code is unique within its configuration.
 */
#[ORM\Entity(repositoryClass: \App\Repository\LayoutZoneRepository::class)]
#[ORM\Table(name: 'layout_zone')]
#[ORM\UniqueConstraint(name: 'uk_layout_zone_layout_code', columns: ['seating_layout_id', 'code'])]
class LayoutZone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'zones')]
    #[ORM\JoinColumn(nullable: false)]
    private SeatingLayout $seatingLayout;

    #[ORM\Column(type: 'string', length: 16)]
    private string $code;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, LayoutPlace> */
    #[ORM\OneToMany(mappedBy: 'layoutZone', targetEntity: LayoutPlace::class)]
    private Collection $layoutPlaces;

    public function __construct(SeatingLayout $seatingLayout, string $code, string $name, ?string $description = null)
    {
        $this->seatingLayout = $seatingLayout;
        $this->code = $code;
        $this->name = $name;
        $this->description = $description;
        $this->layoutPlaces = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeatingLayout(): SeatingLayout
    {
        return $this->seatingLayout;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    /** @return Collection<int, LayoutPlace> */
    public function getLayoutPlaces(): Collection
    {
        return $this->layoutPlaces;
    }
}
