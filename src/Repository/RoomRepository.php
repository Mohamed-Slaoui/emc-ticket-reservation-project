<?php

namespace App\Repository;

use App\DTO\Pagination;
use App\Entity\Room;
use App\Entity\Venue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Room>
 */
class RoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Room::class);
    }

    /**
     * The rooms of a venue, in alphabetical order.
     *
     * @return list<Room>
     */
    public function findForVenue(Venue $venue): array
    {
        /** @var list<Room> $rooms */
        $rooms = $this->createQueryBuilder('r')
            ->andWhere('r.venue = :venue')
            ->setParameter('venue', $venue)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rooms;
    }

    public function countForVenue(Venue $venue): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.venue = :venue')
            ->setParameter('venue', $venue)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * A page of rooms, in alphabetical order.
     *
     * The page can be restricted to a single venue — the filter of the
     * rooms screen.
     *
     * @return array{0: list<Room>, 1: Pagination}
     */
    public function paginate(int $page = 1, int $perPage = Pagination::DEFAULT_PER_PAGE, ?Venue $venue = null): array
    {
        $query = $this->createQueryBuilder('r')
            ->orderBy('r.name', 'ASC');

        if (null !== $venue) {
            $query
                ->andWhere('r.venue = :venue')
                ->setParameter('venue', $venue);
        }

        $countQuery = clone $query;
        $total = (int) $countQuery
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $pagination = Pagination::create($page, $perPage, $total);

        /** @var list<Room> $rooms */
        $rooms = $query
            ->setFirstResult($pagination->getOffset())
            ->setMaxResults($pagination->perPage)
            ->getQuery()
            ->getResult();

        return [$rooms, $pagination];
    }
}
