<?php

namespace App\Tests\Integration;

use App\Entity\BookingPolicy;
use App\Entity\Event;
use App\Entity\Performance;
use App\Entity\PerformancePrice;
use App\Entity\Tariff;
use App\Enum\EventType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The three guarantees closed during the Phase 1 review are enforced by the
 * **database**, not only by the entities:
 *
 * 1. a single current price per (performance, zone, tariff) — §5.2;
 * 2. the inventory state invariant — §5.4;
 * 3. a single booking policy row.
 */
class DatabaseInvariantsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->bootWithFixtures();
    }

    public function test_a_second_current_price_for_the_same_combination_is_rejected(): void
    {
        $em = $this->entityManager();

        $performance = $this->createPerformance();
        $zone = $em->getRepository(\App\Entity\LayoutZone::class)->findOneBy(['code' => 'ORC']);
        $tariff = $em->getRepository(Tariff::class)->findOneBy(['code' => 'PLEIN']);

        $first = new PerformancePrice($performance, $zone, $tariff, '150.00');
        $em->persist($first);
        $em->flush();

        // A new version of the same combination, still current -> rejected:
        // there may be only one current price per (performance, zone, tariff).
        $duplicate = new PerformancePrice($performance, $zone, $tariff, '170.00', 'MAD', 2);

        $this->expectException(UniqueConstraintViolationException::class);
        $em->persist($duplicate);
        $em->flush();
    }

    public function test_retired_price_versions_do_not_conflict_with_the_current_one(): void
    {
        $em = $this->entityManager();

        $performance = $this->createPerformance();
        $zone = $em->getRepository(\App\Entity\LayoutZone::class)->findOneBy(['code' => 'ORC']);
        $tariff = $em->getRepository(Tariff::class)->findOneBy(['code' => 'PLEIN']);

        $current = new PerformancePrice($performance, $zone, $tariff, '150.00');
        $em->persist($current);
        $em->flush();

        // A retired version of the same combination: allowed — its generated
        // combination is NULL, so it does not take part in the unique key.
        $retired = new PerformancePrice($performance, $zone, $tariff, '130.00', 'MAD', 2, false);
        $em->persist($retired);
        $em->flush();

        self::assertTrue($em->contains($retired));
        self::assertFalse($retired->isCurrent());
    }

    public function test_retiring_the_current_price_frees_the_combination_for_a_new_current_version(): void
    {
        $em = $this->entityManager();

        $performance = $this->createPerformance();
        $zone = $em->getRepository(\App\Entity\LayoutZone::class)->findOneBy(['code' => 'ORC']);
        $tariff = $em->getRepository(Tariff::class)->findOneBy(['code' => 'PLEIN']);

        $first = new PerformancePrice($performance, $zone, $tariff, '150.00');
        $em->persist($first);
        $em->flush();

        $first->retire();
        $em->flush();

        // The combination is free again.
        $second = new PerformancePrice($performance, $zone, $tariff, '180.00', 'MAD', 2);
        $em->persist($second);
        $em->flush();

        self::assertTrue($second->isCurrent());
    }

    public function test_the_inventory_state_invariant_is_enforced_by_the_database(): void
    {
        $em = $this->entityManager();

        $performance = $this->createPerformance();
        $layoutPlace = $em->getRepository(\App\Entity\LayoutPlace::class)
            ->findOneBy(['seatingLayout' => $performance->getSeatingLayout()]);

        // A HELD seat without a reservation and without an expiry violates the
        // invariant of docs/MODELE.md §5.4. The entity can never produce such
        // a row, so it is written directly to prove the database refuses it.
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->expectExceptionMessage('ck_performance_place_state');

        $em->getConnection()->executeStatement(
            'INSERT INTO performance_place (status, current_reservation_id, hold_expires_at, created_at, performance_id, layout_place_id)
             VALUES (?, NULL, NULL, NOW(), ?, ?)',
            ['HELD', $performance->getId(), $layoutPlace->getId()],
        );
    }

    public function test_a_second_booking_policy_row_is_rejected(): void
    {
        $em = $this->entityManager();

        $existing = $em->getRepository(BookingPolicy::class)->findOneBy([]);
        self::assertNotNull($existing, 'The fixtures seed exactly one policy row.');

        $second = new BookingPolicy(15, $existing->getUpdatedBy());

        $this->expectException(UniqueConstraintViolationException::class);
        $em->persist($second);
        $em->flush();
    }

    /**
     * Creates and persists an event and its performance, which the fixtures
     * deliberately do not provide (they belong to a later phase).
     */
    private function createPerformance(): Performance
    {
        $em = $this->entityManager();

        $layout = $em->getRepository(\App\Entity\SeatingLayout::class)->findOneBy(['version' => 1]);

        $event = new Event('Concert de gala', EventType::Concert);
        $em->persist($event);

        $performance = new Performance($event, $layout, new \DateTimeImmutable('+30 days'));
        $em->persist($performance);
        $em->flush();

        return $performance;
    }
}
