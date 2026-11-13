<?php

namespace App\Service\Pricing;

use App\Entity\LayoutZone;
use App\Entity\Performance;
use App\Entity\PerformancePrice;
use App\Entity\Tariff;
use App\Exception\PricingValidationException;
use App\Repository\LayoutZoneRepository;
use App\Repository\PerformancePriceRepository;
use App\Repository\TariffRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The prices of a performance.
 *
 * A price is set per **zone** and per **tariff**. The prices are
 * **versioned**: changing a price creates a new version and retires the
 * previous one, so the price of an already sold ticket is never altered
 * (`docs/MODELE.md` — the price snapshot of the reservation item references
 * the exact price row that was sold).
 *
 * The matrix is validated before it is saved; an invalid amount is
 * rejected, and the administrator sees the reason next to the cell.
 */
class PerformancePriceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PerformancePriceRepository $prices,
        private readonly LayoutZoneRepository $zones,
        private readonly TariffRepository $tariffs,
    ) {
    }

    /**
     * The current prices of the performance, keyed by zone and tariff.
     *
     * @return array<int, array<int, PerformancePrice>>
     */
    public function currentMatrix(Performance $performance): array
    {
        return $this->prices->findCurrentMatrix($performance);
    }

    /**
     * How many (zone × catalogue tariff) combinations still have no price.
     *
     * The event is not published until every combination is priced — and the
     * catalogue is the four fixed tariffs, so the matrix is zones × 4.
     *
     * A catalogue row that is missing from the database counts as **unpriced**,
     * never as a short matrix: the event cannot be published in that state.
     */
    public function missingPriceCount(Performance $performance): int
    {
        $zones = $this->zones->findForLayout($performance->getSeatingLayout());
        $matrix = $this->currentMatrix($performance);

        $missing = 0;

        foreach ($zones as $zone) {
            $zoneId = (int) $zone->getId();

            foreach ($this->tariffs->catalogue() as $tariff) {
                if (!isset($matrix[$zoneId][(int) $tariff->getId()])) {
                    $missing++;
                }
            }
        }

        return $missing;
    }

    public function isComplete(Performance $performance): bool
    {
        return 0 === $this->missingPriceCount($performance);
    }

    /**
     * Saves the prices of a performance.
     *
     * Each cell is either a positive amount or an empty string. An empty
     * cell **unsets** the price of that combination: the current price is
     * retired and none takes its place, so pricing is incomplete again.
     *
     * The whole save runs inside a transaction, in which the performance
     * row is locked, so two administrators cannot set different prices at
     * the same time.
     *
     * @param array<int, array<int, string>> $amounts [zoneId => [tariffId => raw amount]]
     *
     * @throws PricingValidationException when an amount cannot be parsed
     */
    public function save(Performance $performance, array $amounts): void
    {
        $parsed = [];

        foreach ($amounts as $zoneId => $tariffs) {
            foreach ($tariffs as $tariffId => $raw) {
                $parsed[(int) $zoneId][(int) $tariffId] = $this->parseAmount($raw);
            }
        }

        $this->entityManager->wrapInTransaction(function () use ($performance, $parsed): void {
            // The performance row is locked for the save.
            $locked = $this->entityManager->find(Performance::class, $performance->getId(), LockMode::PESSIMISTIC_WRITE);

            if (null === $locked) {
                throw new \RuntimeException('The performance could not be locked.');
            }

            $matrix = $this->prices->findCurrentMatrix($performance);

            foreach ($this->zones->findForLayout($performance->getSeatingLayout()) as $zone) {
                foreach ($this->tariffs->catalogue() as $tariff) {
                    $zoneId = (int) $zone->getId();
                    $tariffId = (int) $tariff->getId();

                    $newAmount = $parsed[$zoneId][$tariffId] ?? null;
                    $current = $matrix[$zoneId][$tariffId] ?? null;

                    if (null === $newAmount) {
                        if (null !== $current) {
                            // The cell is empty: the price is unset.
                            $current->retire();
                        }

                        continue;
                    }

                    if (null !== $current && $current->getAmount() === $newAmount) {
                        // Nothing changed for that combination.
                        continue;
                    }

                    if (null !== $current) {
                        $current->retire();
                        // The unique key on the current price is freed only
                        // when this update is flushed: it must happen before
                        // the new version is inserted.
                        $this->entityManager->flush();
                    }

                    $this->entityManager->persist(new PerformancePrice(
                        $performance,
                        $zone,
                        $tariff,
                        $newAmount,
                        $current?->getCurrency() ?? 'MAD',
                        null === $current ? 1 : $current->getVersion() + 1,
                    ));
                }
            }

            $this->entityManager->flush();
        });
    }

    /**
     * Parses an amount from a form input.
     *
     * @return string|null The normalized amount, or null when the cell is empty
     *
     * @throws PricingValidationException when the input is not a valid amount
     */
    private function parseAmount(string $raw): ?string
    {
        $raw = trim($raw);

        if ('' === $raw) {
            return null;
        }

        if (!preg_match('/^\d{1,8}([.,]\d{1,2})?$/', $raw)) {
            throw new PricingValidationException(sprintf(
                'The amount "%s" is not a valid price. Use at most 2 decimals, separated by a point.',
                $raw,
            ));
        }

        $normalized = str_replace(',', '.', $raw);
        $float = (float) $normalized;

        if ($float < 0) {
            throw new PricingValidationException('A price cannot be negative.');
        }

        return number_format($float, 2, '.', '');
    }
}
