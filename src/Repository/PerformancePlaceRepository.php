<?php

namespace App\Repository;

use App\Entity\Performance;
use App\Entity\PerformancePlace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
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

    /**
     * The inventory rows of a performance, locked for a critical section.
     *
     * The rows are locked **before** any re-check, so two holds taken at the
     * same moment are serialised and only one of them sees a seat still on
     * sale (INV-02).
     *
     * @param list<int> $placeIds The performance_place ids (the inventory rows), unique.
     *
     * @return list<PerformancePlace>
     */
    public function findForLockByIds(Performance $performance, array $placeIds): array
    {
        if ([] === $placeIds) {
            return [];
        }

        /** @var list<PerformancePlace> $places */
        $places = $this->createQueryBuilder('pp')
            ->leftJoin('pp.layoutPlace', 'lp')
            ->addSelect('lp')
            ->andWhere('pp.performance = :performance')
            ->setParameter('performance', $performance)
            ->andWhere('pp.id IN (:placeIds)')
            ->setParameter('placeIds', $placeIds)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
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
