<?php

namespace App\Repository;

use App\Entity\Performance;
use App\Entity\SeatingLayout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Performance>
 */
class PerformanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Performance::class);
    }

    /**
     * The number of performances that use the configuration.
     *
     * A configuration used by a performance is **immutable**: any evolution
     * creates a new version, so the past sales are never altered.
     */
    public function countForLayout(SeatingLayout $layout): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.seatingLayout = :layout')
            ->setParameter('layout', $layout)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
