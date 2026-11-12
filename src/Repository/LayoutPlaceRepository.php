<?php

namespace App\Repository;

use App\Entity\LayoutPlace;
use App\Entity\LayoutZone;
use App\Entity\Place;
use App\Entity\SeatingLayout;
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

    /**
     * The seats mapped in a configuration, in their display order.
     *
     * @return list<LayoutPlace>
     */
    public function findForLayout(SeatingLayout $layout): array
    {
        /** @var list<LayoutPlace> $layoutPlaces */
        $layoutPlaces = $this->createQueryBuilder('lp')
            ->andWhere('lp.seatingLayout = :layout')
            ->setParameter('layout', $layout)
            ->orderBy('lp.displayOrder', 'ASC')
            ->addOrderBy('lp.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $layoutPlaces;
    }

    public function countForLayout(SeatingLayout $layout): int
    {
        return (int) $this->createQueryBuilder('lp')
            ->select('COUNT(lp.id)')
            ->andWhere('lp.seatingLayout = :layout')
            ->setParameter('layout', $layout)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The number of seats mapped in a zone.
     */
    public function countForZone(LayoutZone $zone): int
    {
        return (int) $this->createQueryBuilder('lp')
            ->select('COUNT(lp.id)')
            ->andWhere('lp.layoutZone = :zone')
            ->setParameter('zone', $zone)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The seats mapped in a zone.
     *
     * @return list<LayoutPlace>
     */
    public function findForZone(LayoutZone $zone): array
    {
        /** @var list<LayoutPlace> $layoutPlaces */
        $layoutPlaces = $this->createQueryBuilder('lp')
            ->andWhere('lp.layoutZone = :zone')
            ->setParameter('zone', $zone)
            ->orderBy('lp.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();

        return $layoutPlaces;
    }

    /**
     * The mappings of the place in the seating configurations.
     *
     * @return list<LayoutPlace>
     */
    public function findForPlace(Place $place): array
    {
        /** @var list<LayoutPlace> $layoutPlaces */
        $layoutPlaces = $this->createQueryBuilder('lp')
            ->andWhere('lp.place = :place')
            ->setParameter('place', $place)
            ->orderBy('lp.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $layoutPlaces;
    }

    /**
     * The number of seats of the configuration that a performance has
     * already turned into inventory.
     *
     * Such a seat cannot be removed: it is the seat of a past sale.
     */
    public function countUsedByPerformance(SeatingLayout $layout): int
    {
        return (int) $this->createQueryBuilder('lp')
            ->select('COUNT(pp.id)')
            ->innerJoin('lp.performancePlaces', 'pp')
            ->andWhere('lp.seatingLayout = :layout')
            ->setParameter('layout', $layout)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
