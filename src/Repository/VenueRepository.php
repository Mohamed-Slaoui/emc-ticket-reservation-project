<?php

namespace App\Repository;

use App\DTO\Pagination;
use App\Entity\Venue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Venue>
 */
class VenueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Venue::class);
    }

    /**
     * A page of venues, in alphabetical order.
     *
     * @return array{0: list<Venue>, 1: Pagination}
     */
    public function paginate(int $page = 1, int $perPage = Pagination::DEFAULT_PER_PAGE): array
    {
        $query = $this->createQueryBuilder('v')
            ->orderBy('v.name', 'ASC');

        $countQuery = clone $query;
        $total = (int) $countQuery
            ->select('COUNT(v.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $pagination = Pagination::create($page, $perPage, $total);

        /** @var list<Venue> $venues */
        $venues = $query
            ->setFirstResult($pagination->getOffset())
            ->setMaxResults($pagination->perPage)
            ->getQuery()
            ->getResult();

        return [$venues, $pagination];
    }
}
