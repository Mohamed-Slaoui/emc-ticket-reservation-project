<?php

namespace App\Repository;

use App\DTO\Pagination;
use App\Entity\Place;
use App\Entity\Room;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Place>
 */
class PlaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Place::class);
    }

    /**
     * The places of a room, ordered by row then seat number.
     *
     * The whole set, without pagination: the seat map of a room draws
     * every place at once.
     *
     * @return list<Place>
     */
    public function findForRoom(Room $room): array
    {
        /** @var list<Place> $places */
        $places = $this->createQueryBuilder('p')
            ->andWhere('p.room = :room')
            ->setParameter('room', $room)
            ->orderBy('p.rowLabel', 'ASC')
            ->addOrderBy('p.seatNumber', 'ASC')
            ->addOrderBy('p.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $places;
    }

    public function countForRoom(Room $room): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.room = :room')
            ->setParameter('room', $room)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The codes already used in a room — the unique key of the room.
     *
     * @return list<string>
     */
    public function findCodesForRoom(Room $room): array
    {
        /** @var list<string> $codes */
        $codes = $this->createQueryBuilder('p')
            ->select('p.code')
            ->andWhere('p.room = :room')
            ->setParameter('room', $room)
            ->getQuery()
            ->getSingleColumnResult();

        return $codes;
    }

    public function existsInRoom(Room $room, string $code): bool
    {
        return in_array($code, $this->findCodesForRoom($room), true);
    }

    /**
     * A page of places, ordered by row then seat number.
     *
     * The page can be restricted to a single room — the filter of the
     * places screen. A room holds hundreds of places, so the screen shows
     * them a few at a time.
     *
     * @return array{0: list<Place>, 1: Pagination}
     */
    public function paginate(int $page = 1, int $perPage = Pagination::DEFAULT_PER_PAGE, ?Room $room = null): array
    {
        $query = $this->createQueryBuilder('p')
            ->orderBy('p.room', 'ASC')
            ->addOrderBy('p.rowLabel', 'ASC')
            ->addOrderBy('p.seatNumber', 'ASC')
            ->addOrderBy('p.code', 'ASC');

        if (null !== $room) {
            $query
                ->andWhere('p.room = :room')
                ->setParameter('room', $room);
        }

        $countQuery = clone $query;
        $total = (int) $countQuery
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $pagination = Pagination::create($page, $perPage, $total);

        /** @var list<Place> $places */
        $places = $query
            ->setFirstResult($pagination->getOffset())
            ->setMaxResults($pagination->perPage)
            ->getQuery()
            ->getResult();

        return [$places, $pagination];
    }
}
