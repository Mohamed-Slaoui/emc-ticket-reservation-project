<?php

namespace App\Repository;

use App\Entity\LayoutZone;
use App\Entity\SeatingLayout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LayoutZone>
 */
class LayoutZoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LayoutZone::class);
    }

    /**
     * The zones of a configuration, in their creation order.
     *
     * @return list<LayoutZone>
     */
    public function findForLayout(SeatingLayout $layout): array
    {
        /** @var list<LayoutZone> $zones */
        $zones = $this->createQueryBuilder('z')
            ->andWhere('z.seatingLayout = :layout')
            ->setParameter('layout', $layout)
            ->orderBy('z.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $zones;
    }

    /**
     * The code is unique within a configuration.
     */
    public function existsCodeInLayout(SeatingLayout $layout, string $code): bool
    {
        return null !== $this->findOneBy(['seatingLayout' => $layout, 'code' => $code]);
    }

    public function countForLayout(SeatingLayout $layout): int
    {
        return (int) $this->createQueryBuilder('z')
            ->select('COUNT(z.id)')
            ->andWhere('z.seatingLayout = :layout')
            ->setParameter('layout', $layout)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
