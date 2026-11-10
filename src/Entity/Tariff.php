<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pricing category: full, reduced, group, invitation (docs/DECISIONS.md Q5).
 * `requiresEligibilityProof` signals whether the seller must verify a
 * supporting document (reduced tariff) — checked server-side, in person.
 */
#[ORM\Entity(repositoryClass: \App\Repository\TariffRepository::class)]
#[ORM\Table(name: 'tariff')]
class Tariff
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 16, unique: true)]
    private string $code;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $requiresEligibilityProof = false;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    /** @var Collection<int, PerformancePrice> */
    #[ORM\OneToMany(mappedBy: 'tariff', targetEntity: PerformancePrice::class)]
    private Collection $performancePrices;

    public function __construct(string $code, string $name, bool $requiresEligibilityProof = false, bool $isActive = true)
    {
        $this->code = $code;
        $this->name = $name;
        $this->requiresEligibilityProof = $requiresEligibilityProof;
        $this->isActive = $isActive;
        $this->performancePrices = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function requiresEligibilityProof(): bool
    {
        return $this->requiresEligibilityProof;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /** @return Collection<int, PerformancePrice> */
    public function getPerformancePrices(): Collection
    {
        return $this->performancePrices;
    }
}
