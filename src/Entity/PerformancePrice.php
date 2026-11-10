<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Price of a tariff for a performance and a zone.
 * Versioned (docs/MODELE.md Q1): a tariff change for future sales creates a
 * new version instead of overwriting, so already-sold tickets keep their price.
 */
#[ORM\Entity(repositoryClass: \App\Repository\PerformancePriceRepository::class)]
#[ORM\Table(name: 'performance_price')]
#[ORM\UniqueConstraint(name: 'uk_performance_price_combo', columns: ['performance_id', 'layout_zone_id', 'tariff_id', 'version'])]
#[ORM\Index(name: 'idx_performance_price_current', columns: ['performance_id', 'tariff_id', 'is_current'])]
class PerformancePrice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'performancePrices')]
    #[ORM\JoinColumn(nullable: false)]
    private Performance $performance;

    #[ORM\ManyToOne(targetEntity: LayoutZone::class)]
    #[ORM\JoinColumn(nullable: false)]
    private LayoutZone $layoutZone;

    #[ORM\ManyToOne(inversedBy: 'performancePrices')]
    #[ORM\JoinColumn(nullable: false)]
    private Tariff $tariff;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency = 'MAD';

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $version = 1;

    #[ORM\Column(type: 'boolean')]
    private bool $isCurrent = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Performance $performance, LayoutZone $layoutZone, Tariff $tariff, string $amount, string $currency = 'MAD', int $version = 1, bool $isCurrent = true)
    {
        $this->performance = $performance;
        $this->layoutZone = $layoutZone;
        $this->tariff = $tariff;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->version = $version;
        $this->isCurrent = $isCurrent;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPerformance(): Performance
    {
        return $this->performance;
    }

    public function getLayoutZone(): LayoutZone
    {
        return $this->layoutZone;
    }

    public function getTariff(): Tariff
    {
        return $this->tariff;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function isCurrent(): bool
    {
        return $this->isCurrent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Retires this price version (a new, current version takes over).
     */
    public function retire(): void
    {
        $this->isCurrent = false;
    }
}
