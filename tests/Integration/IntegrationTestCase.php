<?php

namespace App\Tests\Integration;

use App\Tests\DoctrineTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class of the integration suite: boots the kernel and prepares the test
 * database.
 *
 * The database bootstrapping lives in {@see DoctrineTestTrait} so that the
 * functional suite does not depend on this class — the two suites stay
 * independent.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    use DoctrineTestTrait;

    /**
     * Boots the kernel and loads the fixtures.
     */
    protected function bootWithFixtures(): void
    {
        self::bootKernel();
        $this->loadFixtures();
    }
}
