<?php

namespace App\Repository;

use App\DTO\Pagination;
use App\Entity\Event;
use App\Entity\EventStatus;
use App\Entity\EventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * A page of events, the most recent first.
     *
     * @return array{0: list<Event>, 1: Pagination}
     */
    public function paginate(
        int $page = 1,
        int $perPage = Pagination::DEFAULT_PER_PAGE,
        ?EventStatus $status = null,
        ?EventType $type = null,
    ): array {
        $query = $this->createQueryBuilder('e')
            ->orderBy('e.id', 'DESC');

        if (null !== $status) {
            $query
                ->andWhere('e.status = :status')
                ->setParameter('status', $status);
        }

        if (null !== $type) {
            $query
                ->andWhere('e.type = :type')
                ->setParameter('type', $type);
        }

        $countQuery = clone $query;
        $total = (int) $countQuery
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $pagination = Pagination::create($page, $perPage, $total);

        /** @var list<Event> $events */
        $events = $query
            ->setFirstResult($pagination->getOffset())
            ->setMaxResults($pagination->perPage)
            ->getQuery()
            ->getResult();

        return [$events, $pagination];
    }
}
