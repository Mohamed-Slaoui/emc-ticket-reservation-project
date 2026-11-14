<?php

namespace App\Repository;

use App\Entity\Performance;
use App\Entity\PerformancePrice;
use App\Entity\Tariff;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PerformancePrice>
 */
class PerformancePriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PerformancePrice::class);
    }

    /**
     * The current price for each (zone, tariff) combination.
     *
     * @return array<int, array<int, PerformancePrice>> keyed by zone ID
     *                                                    then by tariff ID
     */
    public function findCurrentMatrix(Performance $performance): array
    {
        /** @var list<PerformancePrice> $prices */
        $prices = $this->createQueryBuilder('pp')
            ->andWhere('pp.performance = :performance')
            ->setParameter('performance', $performance)
            ->andWhere('pp.isCurrent = true')
            ->getQuery()
            ->getResult();

        $matrix = [];

        foreach ($prices as $price) {
            $matrix[(int) $price->getLayoutZone()->getId()][(int) $price->getTariff()->getId()] = $price;
        }

        return $matrix;
    }

    /**
     * The lowest current price across zones, per performance, for the given
     * tariff — shown as "from X MAD" on the programme.
     *
     * @param list<int> $performanceIds
     * @return array<int, string> the performance id → its lowest amount
     */
    public function findFromAmounts(array $performanceIds, Tariff $tariff): array
    {
        if ([] === $performanceIds) {
            return [];
        }

        /** @var list<array{pid: int|string, amount: string}> $rows */
        $rows = $this->createQueryBuilder('pp')
            ->select('IDENTITY(pp.performance) AS pid, MIN(pp.amount) AS amount')
            ->andWhere('pp.performance IN (:performanceIds)')
            ->setParameter('performanceIds', $performanceIds)
            ->andWhere('pp.tariff = :tariff')
            ->setParameter('tariff', $tariff)
            ->andWhere('pp.isCurrent = true')
            ->groupBy('pp.performance')
            ->getQuery()
            ->getArrayResult();

        $from = [];

        foreach ($rows as $row) {
            $from[(int) $row['pid']] = $row['amount'];
        }

        return $from;
    }

    /**
     * Every price version of a performance, most recently set first.
     *
     * @return list<PerformancePrice>
     */
    public function findHistoryForPerformance(Performance $performance): array
    {
        /** @var list<PerformancePrice> $prices */
        $prices = $this->createQueryBuilder('pp')
            ->andWhere('pp.performance = :performance')
            ->setParameter('performance', $performance)
            ->orderBy('pp.createdAt', 'DESC')
            ->addOrderBy('pp.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $prices;
    }
}
