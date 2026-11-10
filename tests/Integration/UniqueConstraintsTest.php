<?php

namespace App\Tests\Integration;

use App\DataFixtures\AppFixtures;
use App\Entity\Event;
use App\Entity\LayoutPlace;
use App\Entity\LayoutZone;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Entity\SeatingLayout;
use App\Entity\User;
use App\Enum\EventType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The uniqueness rules live in the **DATABASE**, not only in the entities
 * (docs/MODELE.md §2, §5.2, BR-02). These tests prove the constraints fire.
 */
class UniqueConstraintsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->bootWithFixtures();
    }

    /**
     * Asserts that flushing $operation violates a unique constraint, then
     * rolls the transaction back so the shared test database stays clean for
     * the next test.
     */
    private function assertFlushViolatesUniqueConstraint(\Closure $operation): void
    {
        try {
            $this->entityManager()->wrapInTransaction($operation);
            self::fail('Expected a UniqueConstraintViolationException, but the flush succeeded.');
        } catch (UniqueConstraintViolationException $expected) {
            // The flush violated the constraint. wrapInTransaction already
            // rolled the transaction back: the shared test DB stays clean.
            self::assertTrue(true);
        }
    }

    public function test_user_email_is_unique(): void
    {
        $existing = $this->entityManager()->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]);

        $duplicate = new User(
            AppFixtures::USER_ADMIN_EMAIL,
            'hashed-value-is-irrelevant-here',
            'X',
            'Y',
        );

        $this->assertFlushViolatesUniqueConstraint(function () use ($duplicate): void {
            $this->entityManager()->persist($duplicate);
            $this->entityManager()->flush();
        });
    }

    public function test_layout_place_maps_a_seat_only_once_per_layout(): void
    {
        $em = $this->entityManager();

        $layout = $em->getRepository(SeatingLayout::class)->findOneBy(['version' => 1]);
        $zone = $em->getRepository(LayoutZone::class)->findOneBy(['code' => 'ORC']);
        $layoutPlace = $em->getRepository(LayoutPlace::class)->findOneBy(['seatingLayout' => $layout]);
        $place = $layoutPlace->getPlace();

        // Same (layout, place) pair: the seat is already mapped in this layout.
        $duplicate = new LayoutPlace($layout, $zone, $place, 999);

        $this->assertFlushViolatesUniqueConstraint(function () use ($em, $duplicate): void {
            $em->persist($duplicate);
            $em->flush();
        });
    }

    public function test_performance_place_is_unique_per_seat_per_performance(): void
    {
        $em = $this->entityManager();

        $layout = $em->getRepository(SeatingLayout::class)->findOneBy(['version' => 1]);
        $layoutPlace = $em->getRepository(LayoutPlace::class)->findOneBy(['seatingLayout' => $layout]);

        // The event must be persisted first: there is no cascade-persist on
        // Performance::event, and Doctrine rejects a new entity referenced by
        // another new entity.
        $event = new Event('Hamlet', EventType::Theatre);
        $em->persist($event);

        $performance = new Performance($event, $layout, new \DateTimeImmutable('+7 days'));
        $em->persist($performance);
        $em->flush();

        $seat = new PerformancePlace($performance, $layoutPlace);
        $em->persist($seat);
        $em->flush();

        // Same seat, same performance: the inventory row must be unique.
        $duplicate = new PerformancePlace($performance, $layoutPlace);

        $this->assertFlushViolatesUniqueConstraint(function () use ($em, $duplicate): void {
            $em->persist($duplicate);
            $em->flush();
        });
    }
}
