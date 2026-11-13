<?php

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\BookingPolicy;
use App\Entity\LayoutPlace;
use App\Entity\LayoutZone;
use App\Entity\Place;
use App\Entity\SeatingLayout;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo data (docs/DECISIONS.md A6 / fixes rules §3): realistic, French,
 * deterministic — one venue, 400 seats, one active configuration, tariffs,
 * demo accounts and the booking policy.
 */
class FixturesTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        $this->bootWithFixtures();
    }

    public function test_fixtures_populate_the_venue_model(): void
    {
        $em = $this->entityManager();

        $venue = $em->getRepository(\App\Entity\Venue::class)->findOneBy([]);
        self::assertNotNull($venue);
        self::assertSame('Théâtre Al-Mansour', $venue->getName());

        $room = $venue->getRooms()->first();
        self::assertNotFalse($room);

        self::assertCount(10 * 40, $room->getPlaces());

        $layout = $em->getRepository(SeatingLayout::class)->findOneBy(['version' => 1]);
        self::assertNotNull($layout, 'The v1 configuration exists and is active.');
        self::assertTrue($layout->isActive());

        $expected = ['ORC' => 240, 'MEZ' => 80, 'BAL' => 80];
        $zones = $em->getRepository(LayoutZone::class)->findBy([], ['code' => 'ASC']);
        self::assertCount(3, $zones);
        foreach ($zones as $zone) {
            self::assertSame($expected[$zone->getCode()], $zone->getLayoutPlaces()->count(), "Zone {$zone->getCode()} has the right number of configured seats.");
        }

        self::assertCount(400, $em->getRepository(LayoutPlace::class)->findAll(), 'Every seat is mapped exactly once (UK seating_layout_id + place_id).');
    }

    public function test_fixtures_create_the_three_demo_accounts(): void
    {
        $em = $this->entityManager();
        $users = $em->getRepository(User::class)->findBy([], ['email' => 'ASC']);

        self::assertCount(3, $users);

        $roles = [];
        foreach ($users as $user) {
            $roles[$user->getEmail()] = $user->getRoles();
        }

        self::assertSame(
            [User::ROLE_ADMIN],
            array_values(array_intersect($roles[AppFixtures::USER_ADMIN_EMAIL], [User::ROLE_ADMIN])),
        );
        self::assertContains(User::ROLE_BOX_OFFICE, $roles[AppFixtures::USER_BOX_OFFICE_EMAIL]);
        self::assertContains(User::ROLE_CUSTOMER, $roles[AppFixtures::USER_CUSTOMER_EMAIL]);
        self::assertNotContains(User::ROLE_CUSTOMER, $roles[AppFixtures::USER_ADMIN_EMAIL], 'Roles are explicit, not cumulative.');
    }

    public function test_demo_passwords_are_properly_hashed(): void
    {
        $em = $this->entityManager();

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $customer = $em->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]);

        self::assertNotSame(AppFixtures::DEMO_PASSWORD, $customer->getPassword(), 'The stored value is a hash, never the plain password.');

        $fresh = new User($customer->getEmail(), 'x', 'K', 'O');
        self::assertTrue($hasher->isPasswordValid($customer, AppFixtures::DEMO_PASSWORD));
        self::assertFalse($hasher->isPasswordValid($fresh, 'wrong-password'));
    }

    public function test_booking_policy_is_seeded(): void
    {
        $policy = $this->entityManager()->getRepository(BookingPolicy::class)->findOneBy([]);

        self::assertNotNull($policy);
        self::assertSame(10, $policy->getHoldDurationMinutes(), 'Default hold: 10 minutes (docs/DECISIONS.md §3.1).');
        self::assertNotNull($policy->getUpdatedBy());
        self::assertSame(AppFixtures::USER_ADMIN_EMAIL, $policy->getUpdatedBy()->getEmail());
    }

    public function test_tariffs_are_seeded(): void
    {
        $em = $this->entityManager();
        $tariffs = $em->getRepository(\App\Entity\Tariff::class)->findBy([], ['code' => 'ASC']);

        self::assertCount(4, $tariffs);
        $codes = array_map(static fn ($t) => $t->getCode(), $tariffs);
        self::assertSame(['FULL', 'GROUP', 'INVITATION', 'REDUCED'], $codes);

        $reduced = $em->getRepository(\App\Entity\Tariff::class)->findOneBy(['code' => 'REDUCED']);
        self::assertTrue($reduced->requiresEligibilityProof(), 'Reduced tariff requires a supporting document (Q5).');
    }
}
