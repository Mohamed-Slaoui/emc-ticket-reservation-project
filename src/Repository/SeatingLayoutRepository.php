<?php

namespace App\Repository;

use App\DTO\Pagination;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeatingLayout>
 */
class SeatingLayoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeatingLayout::class);
    }

    /**
     * The configurations of a room, the most recent version first.
     *
     * @return list<SeatingLayout>
     */
    public function findForRoom(Room $room): array
    {
        /** @var list<SeatingLayout> $layouts */
        $layouts = $this->createQueryBuilder('l')
            ->andWhere('l.room = :room')
            ->setParameter('room', $room)
            ->orderBy('l.version', 'DESC')
            ->getQuery()
            ->getResult();

        return $layouts;
    }

    /**
     * The number of configurations of the room.
     */
    public function countForRoom(Room $room): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.room = :room')
            ->setParameter('room', $room)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The version number a new configuration of the room would take.
     *
     * The version is unique within the room, so it is the highest one plus
     * one.
     */
    public function nextVersion(Room $room): int
    {
        $highest = (int) $this->createQueryBuilder('l')
            ->select('MAX(l.version)')
            ->andWhere('l.room = :room')
            ->setParameter('room', $room)
            ->getQuery()
            ->getSingleScalarResult();

        return $highest + 1;
    }

    /**
     * A page of configurations, the most recent first.
     *
     * @return array{0: list<SeatingLayout>, 1: Pagination}
     */
    public function paginate(int $page = 1, int $perPage = Pagination::DEFAULT_PER_PAGE, ?Room $room = null): array
    {
        $query = $this->createQueryBuilder('l')
            ->orderBy('l.version', 'DESC')
            ->addOrderBy('l.id', 'DESC');

        if (null !== $room) {
            $query
                ->andWhere('l.room = :room')
                ->setParameter('room', $room);
        }

        $countQuery = clone $query;
        $total = (int) $countQuery
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $pagination = Pagination::create($page, $perPage, $total);

        /** @var list<SeatingLayout> $layouts */
        $layouts = $query
            ->setFirstResult($pagination->getOffset())
            ->setMaxResults($pagination->perPage)
            ->getQuery()
            ->getResult();

        return [$layouts, $pagination];
    }
}
