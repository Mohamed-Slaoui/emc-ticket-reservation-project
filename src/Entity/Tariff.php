<?php

namespace App\Entity;

use App\Enum\TariffCode;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pricing category: full, reduced, group, invitation (docs/DECISIONS.md Q5).
 *
 * The catalogue is **fixed** — exactly these four categories, defined by the
 * application (`TariffCode`) and enforced by the database (`ck_tariff_code`
 * and the generated `name` column). The only
 * way to create a tariff is with a `TariffCode` case; there is no setter, so
 * a tariff can never be renamed, deactivated or given a new eligibility rule,
 * not even by the application itself.
 */
#[ORM\Entity(repositoryClass: \App\Repository\TariffRepository::class)]
#[ORM\Table(name: 'tariff')]
class Tariff
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** The code of the category — one of the four `TariffCode` cases. Unique, and CHECK-constrained. */
    #[ORM\Column(type: 'string', length: 16, unique: true)]
    private string $code;

    /**
     * The label of the tariff, computed by the database from the code — the
     * application never writes it, it cannot drift and it cannot be edited.
     */
    #[ORM\Column(
        name: 'name',
        type: 'string',
        length: 100,
        nullable: true,
        insertable: false,
        updatable: false,
        generated: 'ALWAYS',
        columnDefinition: "VARCHAR(100) GENERATED ALWAYS AS (CASE code WHEN 'FULL' THEN 'Full tariff' WHEN 'REDUCED' THEN 'Reduced tariff' WHEN 'GROUP' THEN 'Group tariff' WHEN 'INVITATION' THEN 'Invitation' END) STORED",
    )]
    private ?string $name = null;

    /** @var Collection<int, PerformancePrice> */
    #[ORM\OneToMany(mappedBy: 'tariff', targetEntity: PerformancePrice::class)]
    private Collection $performancePrices;

    public function __construct(TariffCode $code)
    {
        $this->code = $code->value;
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
        // Before the row is reloaded, the property is null — the code carries the same label.
        return $this->name ?? TariffCode::from($this->code)->label();
    }

    /**
     * Whether the seller must verify a supporting document in presence — only
     * the reduced tariff requires it (docs/DECISIONS.md Q5). Derived from the
     * code: it is a property of the category, not a setting.
     */
    public function requiresEligibilityProof(): bool
    {
        return TariffCode::Reduced->value === $this->code;
    }

    /**
     * Whether the customer may apply the tariff online — full tariff only
     * (docs/DECISIONS.md Q5). The other three go through the box office or an
     * administrator.
     */
    public function sellableOnline(): bool
    {
        return TariffCode::Full->value === $this->code;
    }

    /**
     * The one-sentence rule attached to the category — shown as a tooltip
     * (docs/DECISIONS.md Q5).
     */
    public function rule(): string
    {
        return TariffCode::from($this->code)->rule();
    }

    /** @return Collection<int, PerformancePrice> */
    public function getPerformancePrices(): Collection
    {
        return $this->performancePrices;
    }
}
