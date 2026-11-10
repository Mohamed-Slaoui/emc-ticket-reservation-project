<?php

namespace App\Tests;

use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Boots the kernel and prepares the test database.
 *
 * Shared by the integration and the functional suites so that neither of them
 * depends on the other.
 *
 * ## Why the EntityManager is resolved lazily
 *
 * `KernelTestCase::bootKernel()` **shuts the previous kernel down and creates
 * a new one**, which means a new container and therefore a **new
 * EntityManager**. An EntityManager captured before a reboot is orphaned: the
 * entities it returns are detached, and Doctrine then reports them as
 * "non-persisted new entities" when a new entity references them.
 *
 * The EntityManager is therefore resolved **on demand** through
 * {@see self::entityManager()}, so it always belongs to the kernel that is
 * currently booted. A test must never store it in a property during
 * `setUp()` and use it afterwards.
 */
trait DoctrineTestTrait
{
    /**
     * Doctrine's ORMPurger TRUNCATE mode cannot run on MySQL 8 as soon as
     * real foreign keys exist (Error 1701), and its DELETE mode ordering is
     * fragile: this explicit, reverse-dependency purge is unambiguous.
     * System tables (doctrine_migration_versions, messenger_messages) are
     * intentionally left untouched.
     */
    private const ORDERED_TABLES = [
        'ticket',
        'refund',
        'payment',
        'outbox_message',
        'reservation_item',
        'reservation',
        'performance_place',
        'performance_price',
        'performance',
        'event',
        'layout_place',
        'layout_zone',
        'seating_layout',
        'place',
        'room',
        'venue',
        'tariff',
        'booking_policy',
        'user',
    ];

    /**
     * The EntityManager of the kernel that is currently booted.
     *
     * Always call this method instead of caching the manager in a property:
     * it guarantees the manager and the container are the same generation.
     */
    protected function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine')->getManager();

        return $manager;
    }

    /**
     * Purges the test database and loads the demo fixtures.
     *
     * The kernel must already be booted — the method deliberately does NOT
     * boot it, because rebooting would orphan any EntityManager a test holds.
     */
    protected function loadFixtures(): void
    {
        $manager = $this->entityManager();
        $connection = $manager->getConnection();

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::ORDERED_TABLES as $table) {
            $connection->executeStatement(sprintf('DELETE FROM `%s`', $table));
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $executor = new ORMExecutor($manager);
        $executor->execute(self::getContainer()->get('doctrine.fixtures.loader')->getFixtures(), true);
        $manager->clear();
    }

    /**
     * Boots the kernel and loads the fixtures — the standard entry point of
     * a database-backed test.
     */
    protected function bootWithFixtures(): void
    {
        self::bootKernel();
        $this->loadFixtures();
    }
}
