<?php

namespace App\Repository;

use App\Entity\LayoutPlace;
use App\Entity\Place;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LayoutPlace>
 */
class LayoutPlaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LayoutPlace::class);
    }

    /**
     * Whether a place is already mapped in a seating layout.
     *
     * A mapped place belongs to the configuration: it can be deactivated, but
     * never deleted — the layout would be broken.
     */
    public function isMapped(Place $place): bool
    {
        return null !== $this->findOneBy(['place' => $place]);
    }

    /**
     * The identifiers of the places that are already mapped in a layout.
     *
     * The list screen needs this for every row it shows: one query is
     * cheaper — and clearer — than one query per row.
     *
     * @param list<Place> $places
     *
     * @return list<int>
     */
    public function findMappedPlaceIds(array $places): array
    {
        if ([] === $places) {
            return [];
        }

        $identifiers = array_map(static fn (Place $place): int => (int) $place->getId(), $places);

        /** @var list<int> $mapped */
        $mapped = $this->createQueryBuilder('lp')
            ->select('p.id')
            ->innerJoin('lp.place', 'p')
            ->andWhere('lp.place IN (:places)')
            ->setParameter('places', $identifiers)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (mixed $id): int => (int) $id, $mapped);
    }

    public function countForPlace(Place $place): int
    {
        return (int) $this->createQueryBuilder('lp')
            ->select('COUNT(lp.id)')
            ->andWhere('lp.place = :place')
            ->setParameter('place', $place)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
