<?php

namespace App\Tests\Functional;

use App\Tests\DoctrineTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base class of the functional suite: boots the kernel through a browser
 * client and prepares the test database.
 *
 * The database bootstrapping lives in {@see DoctrineTestTrait} so that the
 * integration suite does not depend on this class.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    use DoctrineTestTrait;

    /**
     * Boots the kernel through a client and loads the fixtures.
     *
     * The client is created **first** so that the kernel — and therefore
     * the EntityManager — are the ones the requests will use.
     */
    protected function bootWithFixtures(): KernelBrowser
    {
        $client = static::createClient();
        $this->loadFixtures();

        return $client;
    }
}
