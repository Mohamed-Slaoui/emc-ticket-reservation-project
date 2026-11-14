<?php

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    /**
     * The tickets of a reservation, in creation order.
     *
     * @return list<Ticket>
     */
    public function findForReservation(Reservation $reservation): array
    {
        /** @var list<Ticket> $tickets */
        $tickets = $this->createQueryBuilder('t')
            ->leftJoin('t.reservationItem', 'i')
            ->addSelect('i')
            ->andWhere('i.reservation = :reservation')
            ->setParameter('reservation', $reservation)
            ->orderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $tickets;
    }
}
