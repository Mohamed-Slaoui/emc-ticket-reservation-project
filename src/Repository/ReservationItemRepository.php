<?php

namespace App\Repository;

use App\Entity\ReservationItem;
use App\Enum\ReservationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReservationItem>
 */
class ReservationItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReservationItem::class);
    }

    /**
     * Revenue by tariff — the confirmed reservations' items, grouped by the
     * tariff name frozen on the item (docs/WORKFLOWS.md §8).
     *
     * @return list<array{name: string, seats: int, amount: string}>
     */
    public function revenueByTariff(): array
    {
        /** @var list<array{name: string, seats: int|string, amount: string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.tariffNameSnapshot AS name, COUNT(i.id) AS seats, COALESCE(SUM(i.lineTotalSnapshot), 0) AS amount')
            ->leftJoin('i.reservation', 'r')
            ->andWhere('r.status = :confirmed')
            ->setParameter('confirmed', ReservationStatus::Confirmed)
            ->groupBy('i.tariffNameSnapshot')
            ->orderBy('amount', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'name' => (string) $row['name'],
                'seats' => (int) $row['seats'],
                'amount' => (string) $row['amount'],
            ];
        }

        return $out;
    }
}
