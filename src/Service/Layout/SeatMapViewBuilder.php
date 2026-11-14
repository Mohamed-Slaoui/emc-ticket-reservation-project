<?php

namespace App\Service\Layout;

use App\Entity\LayoutZone;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Entity\LayoutPlace;
use App\Enum\InventoryStatus;
use App\Enum\TariffCode;
use App\Repository\PerformancePriceRepository;
use App\Service\Reservation\ReservationService;
use App\Repository\TariffRepository;

/**
 * Builds the seat-map view model — the rows of seats for the templates,
 * shared by the public shop and the box office (INV-10).
 *
 * The availability shown is advisory; what decides is the re-check under
 * the row lock inside `ReservationService::hold()`.
 */
class SeatMapViewBuilder
{
    /** The palette of the zones of a configuration. */
    private const ZONE_COLORS = 6;

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly PerformancePriceRepository $prices,
        private readonly TariffRepository $tariffs,
    ) {
    }

    /**
     * The seat-map view model for a performance.
     *
     * The 7-element tuple consumed by the shop templates, plus the full
     * zone × tariff price map (8th element) for the counter's tariff picker —
     * the seat prices and the running total reprice live as the seller
     * changes the tariff.
     *
     * @return array{0: array<string, list<array{inventoryPlace: PerformancePlace, code: string, zoneId: int, status: InventoryStatus, price: string}>>, 1: array<int, int>, 2: array<int, string|null>, 3: string|null, 4: int, 5: int, 6: array<int, LayoutZone>, 7: array<string, array<int, string|null>>}
     */
    public function build(Performance $performance, TariffCode $tariffCode): array
    {
        $tariff = $this->tariffs->findOneBy(['code' => $tariffCode->value]);
        $tariffId = null !== $tariff ? (int) $tariff->getId() : null;

        $inventory = $this->reservations->inventoryFor($performance);
        $matrix = null !== $tariffId ? $this->prices->findCurrentMatrix($performance) : [];

        $rows = [];
        $zones = [];

        foreach ($inventory as $entry) {
            /** @var LayoutPlace $layoutPlace */
            $layoutPlace = $entry['place']->getLayoutPlace();
            $zones[(int) $entry['zone']->getId()] = $entry['zone'];
        }

        ksort($zones);

        $zoneColors = [];

        foreach (array_values($zones) as $index => $zone) {
            $zoneColors[(int) $zone->getId()] = ($index % self::ZONE_COLORS) + 1;
        }

        $availableCount = 0;

        foreach ($inventory as $entry) {
            if (InventoryStatus::Available === $entry['status']) {
                ++$availableCount;
            }

            $layoutPlace = $entry['place']->getLayoutPlace();
            $zoneId = (int) $entry['zone']->getId();
            $price = isset($matrix[$zoneId][$tariffId]) ? $matrix[$zoneId][$tariffId]->getAmount() : '0.00';
            $rowLabel = $layoutPlace->getPlace()->getRowLabel() ?? '';

            $rows[$rowLabel][] = [
                'inventoryPlace' => $entry['place'],
                'code' => $layoutPlace->getPlace()->getCode(),
                'zoneId' => $zoneId,
                'status' => $entry['status'],
                'price' => $price,
            ];
        }

        ksort($rows);

        $zonePrices = [];

        foreach ($zones as $zone) {
            $zoneId = (int) $zone->getId();
            $zonePrices[$zoneId] = isset($matrix[$zoneId][$tariffId]) ? $matrix[$zoneId][$tariffId]->getAmount() : null;
        }

        $fromAmount = null;

        if ([] !== $zonePrices) {
            $values = array_values(array_filter($zonePrices));

            if ([] !== $values) {
                $fromAmount = number_format(min(array_map('floatval', $values)), 2, '.', '');
            }
        }

        $pricesByTariff = [];

        foreach ($this->tariffs->catalogue() as $tariff) {
            $tId = (int) $tariff->getId();

            foreach ($zones as $zone) {
                $zoneId = (int) $zone->getId();
                $pricesByTariff[$tariff->getCode()][$zoneId] = isset($matrix[$zoneId][$tId]) ? $matrix[$zoneId][$tId]->getAmount() : null;
            }
        }

        return [$rows, $zoneColors, $zonePrices, $fromAmount, $availableCount, count($inventory), $zones, $pricesByTariff];
    }
}
