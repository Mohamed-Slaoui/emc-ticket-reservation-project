<?php

namespace App\Repository;

use App\Entity\Performance;
use App\Entity\PerformancePrice;
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
