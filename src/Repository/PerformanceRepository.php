<?php

namespace App\Repository;

use App\DTO\Pagination;
use App\Entity\Event;
use App\Entity\Performance;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Enum\PerformanceStatus;
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

    /**
     * The performances of an event, the earliest first.
     *
     * @return list<Performance>
     */
    public function findForEvent(Event $event): array
    {
        /** @var list<Performance> $performances */
        $performances = $this->createQueryBuilder('p')
            ->andWhere('p.event = :event')
            ->setParameter('event', $event)
            ->orderBy('p.startsAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $performances;
    }

    public function countForEvent(Event $event): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.event = :event')
            ->setParameter('event', $event)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The performances of the room that occupy it during the given interval.
     *
     * The room is reached **through the configuration**: two performances of
     * two different configurations of the same room conflict just as much
     * as two performances of the same configuration.
     *
     * The intervals are half-open — a show that ends at 14:00 and a show
     * that starts at 14:00 do not overlap. A performance without an end
     * date occupies the room indefinitely after its start.
     *
     * @return list<Performance>
     */
    public function findOverlappingInRoom(
        Room $room,
        \DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
        ?Performance $exclude = null,
    ): array {
        // An open-ended performance occupies the room until the end of time.
        $until = $endsAt ?? new \DateTimeImmutable('9999-12-31 23:59:59');

        $query = $this->createQueryBuilder('p')
            ->innerJoin('p.seatingLayout', 'l')
            ->andWhere('l.room = :room')
            ->setParameter('room', $room)
            // A cancelled performance does not occupy the room.
            ->andWhere('p.status <> :cancelled')
            ->setParameter('cancelled', PerformanceStatus::Cancelled)
            // The existing show starts before the new one ends…
            ->andWhere('p.startsAt < :until')
            ->setParameter('until', $until)
            // …and the existing show ends after the new one starts.
            ->andWhere('(p.endsAt IS NULL OR p.endsAt > :startsAt)')
            ->setParameter('startsAt', $startsAt)
            ->orderBy('p.startsAt', 'ASC');

        if (null !== $exclude) {
            $query
                ->andWhere('p.id <> :exclude')
                ->setParameter('exclude', $exclude->getId());
        }

        /** @var list<Performance> $performances */
        $performances = $query->getQuery()->getResult();

        return $performances;
    }

    /**
     * A page of performances, the earliest first.
     *
     * @return array{0: list<Performance>, 1: Pagination}
     */
    public function paginate(int $page = 1, int $perPage = Pagination::DEFAULT_PER_PAGE, ?Event $event = null): array
    {
        $query = $this->createQueryBuilder('p')
            ->orderBy('p.startsAt', 'ASC')
            ->addOrderBy('p.id', 'ASC');

        if (null !== $event) {
            $query
                ->andWhere('p.event = :event')
                ->setParameter('event', $event);
        }

        $countQuery = clone $query;
        $total = (int) $countQuery
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $pagination = Pagination::create($page, $perPage, $total);

        /** @var list<Performance> $performances */
        $performances = $query
            ->setFirstResult($pagination->getOffset())
            ->setMaxResults($pagination->perPage)
            ->getQuery()
            ->getResult();

        return [$performances, $pagination];
    }
}
