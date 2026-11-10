<?php

namespace App\Tests\Functional;

use App\Tests\DoctrineTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class of the functional suite: boots the kernel and prepares the test
 * database.
 *
 * The database bootstrapping lives in {@see DoctrineTestTrait} so that the
 * integration suite does not depend on this class.
 */
abstract class FunctionalTestCase extends KernelTestCase
{
    use DoctrineTestTrait;
}
