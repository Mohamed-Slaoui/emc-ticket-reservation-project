<?php

namespace App\Repository;

use App\Entity\Refund;
use App\Enum\RefundStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Refund>
 */
class RefundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Refund::class);
    }

    /**
     * The sum of the successful refunds — subtracted from the gross
     * revenue to get the net (docs/WORKFLOWS.md §8).
     */
    public function sumSucceeded(): string
    {
        $result = $this->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.amount), 0)')
            ->andWhere('r.status = :succeeded')
            ->setParameter('succeeded', RefundStatus::Succeeded)
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $result;
    }
}
