<?php

/**
 * Schedules a performance in a **separate process**.
 *
 * The concurrency test spawns two of these: they wait for the same barrier,
 * then try to schedule an overlapping performance in the same room. Only one
 * of them may succeed.
 *
 * Usage:
 *   php schedule_performance.php <resultFile> <readyFile> <barrierFile> <eventId> <layoutId> <startsAt> [<endsAt>]
 */

use App\Entity\Event;
use App\Entity\SeatingLayout;
use App\Service\Event\PerformanceService;
use Symfony\Component\Dotenv\Dotenv;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

// The test environment, exactly like the test suite uses.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv($root.'/.env');
}

[, $resultFile, $readyFile, $barrierFile, $eventId, $layoutId, $startsAt] = $argv;
$endsAt = $argv[7] ?? null;

$kernel = new App\Kernel('test', true);
$kernel->boot();

// The test container gives access to the private services.
$container = $kernel->getContainer()->get('test.service_container');

// The barrier: both processes wait for the same file, so they reach the
// service at the same instant.
file_put_contents($readyFile, 'ready');

while (!file_exists($barrierFile)) {
    usleep(500);
}

try {
    $doctrine = $container->get('doctrine');

    $event = $doctrine->getRepository(Event::class)->find((int) $eventId);
    $layout = $doctrine->getRepository(SeatingLayout::class)->find((int) $layoutId);

    $container->get(PerformanceService::class)->create(
        $event,
        $layout,
        new DateTimeImmutable($startsAt),
        null !== $endsAt ? new DateTimeImmutable($endsAt) : null,
    );

    $result = 'OK';
} catch (Throwable $exception) {
    $result = 'KO: '.$exception->getMessage();
}

file_put_contents($resultFile, $result);
