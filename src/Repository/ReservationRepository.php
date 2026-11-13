<?php

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\ReservationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * The number of reservations an account appears on, either as the author
     * of the sale or as the customer.
     *
     * An account that already appears on a reservation is part of the
     * accounting history: it can be disabled, never deleted.
     */
    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.createdBy = :user OR r.customer = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The hold reservations whose expiry time has passed and whose seats are
     * therefore due back in sale (BR-05).
     *
     * @return list<Reservation>
     */
    public function findExpiredHeld(): array
    {
        /** @var list<Reservation> $reservations */
        $reservations = $this->createQueryBuilder('r')
            ->leftJoin('r.items', 'i')
            ->addSelect('i')
            ->leftJoin('r.performancePlaces', 'pp')
            ->addSelect('pp')
            ->andWhere('r.status = :held')
            ->setParameter('held', ReservationStatus::Held)
            ->andWhere('r.expiresAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();

        return $reservations;
    }
}
