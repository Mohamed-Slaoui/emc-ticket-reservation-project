<?php

namespace App\Repository;

use App\Entity\Performance;
use App\Entity\PerformancePlace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PerformancePlace>
 */
class PerformancePlaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PerformancePlace::class);
    }

    /**
     * The inventory of a performance — one row per seat of its configuration.
     *
     * @return list<PerformancePlace>
     */
    public function findForPerformance(Performance $performance): array
    {
        /** @var list<PerformancePlace> $places */
        $places = $this->createQueryBuilder('pp')
            ->andWhere('pp.performance = :performance')
            ->setParameter('performance', $performance)
            ->orderBy('pp.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $places;
    }

    public function countForPerformance(Performance $performance): int
    {
        return (int) $this->createQueryBuilder('pp')
            ->select('COUNT(pp.id)')
            ->andWhere('pp.performance = :performance')
            ->setParameter('performance', $performance)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
