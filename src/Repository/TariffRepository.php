<?php

namespace App\Repository;

use App\Entity\Tariff;
use App\Enum\TariffCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tariff>
 */
class TariffRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tariff::class);
    }

    /**
     * The four pricing categories of the venue, in the order the matrix displays them.
     *
     * The catalogue is **fixed**: it is seeded by the migrations (production) and
     * the fixtures (development), enforced by the database (`ck_tariff_code`), and
     * it can never hold anything else. This method answers the catalogue in
     * `TariffCode` order — full, reduced, group, invitation — and fails loudly if
     * a row is missing, so an incomplete catalogue can never silently pass.
     *
     * @return list<Tariff>
     *
     * @throws \RuntimeException when a catalogue row is missing
     */
    public function catalogue(): array
    {
        /** @var list<Tariff> $rows */
        $rows = $this->createQueryBuilder('t')->getQuery()->getResult();

        $byCode = [];

        foreach ($rows as $row) {
            $byCode[$row->getCode()] = $row;
        }

        $catalogue = [];

        foreach (TariffCode::cases() as $case) {
            $row = $byCode[$case->value] ?? null;

            if (null === $row) {
                throw new \RuntimeException(sprintf(
                    'The tariff catalogue is incomplete: the "%s" tariff is missing. Run the migrations.',
                    $case->value,
                ));
            }

            $catalogue[] = $row;
        }

        return $catalogue;
    }
}
