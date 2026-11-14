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
     * Finds a ticket by its number **or** by its claim code — what the
     * scanner reads (the number printed on the ticket, or the code the QR
     * image carries).
     */
    public function findByNumberOrClaim(string $code): ?Ticket
    {
        $code = trim($code);

        if ('' === $code) {
            return null;
        }

        /** @var Ticket|null $ticket */
        $ticket = $this->createQueryBuilder('t')
            ->andWhere('t.ticketNumber = :code OR t.qrToken = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();

        return $ticket;
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
