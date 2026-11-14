<?php

namespace App\Repository;

use App\Entity\Payment;
use App\Enum\PaymentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * The gross revenue — the sum of the captured payments.
     * Pending and failed payments are excluded (docs/WORKFLOWS.md §8).
     */
    public function sumSucceeded(): string
    {
        $result = $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amount), 0)')
            ->andWhere('p.status = :succeeded')
            ->setParameter('succeeded', PaymentStatus::Succeeded)
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $result;
    }
}
