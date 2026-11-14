<?php

namespace App\Repository;

use App\Entity\OutboxMessage;
use App\Enum\OutboxStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OutboxMessage>
 */
class OutboxMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OutboxMessage::class);
    }

    /**
     * Pending messages whose next attempt is due (or unset).
     *
     * @return list<OutboxMessage>
     */
    public function findClaimable(\DateTimeImmutable $now, int $limit = 50): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->andWhere('o.nextAttemptAt IS NULL OR o.nextAttemptAt <= :now')
            ->setParameter('status', OutboxStatus::Pending)
            ->setParameter('now', $now)
            ->orderBy('o.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Failed messages whose retry delay has elapsed.
     *
     * @return list<OutboxMessage>
     */
    public function findDueFailed(\DateTimeImmutable $now, int $limit = 50): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->andWhere('o.nextAttemptAt <= :now')
            ->setParameter('status', OutboxStatus::Failed)
            ->setParameter('now', $now)
            ->orderBy('o.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
