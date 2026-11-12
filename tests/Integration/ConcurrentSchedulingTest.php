<?php

namespace App\Tests\Integration;

use App\Entity\Event;
use App\Entity\Performance;
use App\Entity\SeatingLayout;
use PHPUnit\Framework\Attributes\Group;

/**
 * Two administrators schedule an **overlapping** performance in the same
 * room at the same instant: only one of them may succeed.
 *
 * The test really uses **two processes**. The room row is locked by the
 * service (`SELECT … FOR UPDATE`), so the second process waits for the first
 * one, and then sees the performance it has just committed.
 *
 * A sequential test would not prove anything here: the two requests would
 * never overlap, and the second one would simply find the first
 * performance.
 */
#[Group('concurrency')]
class ConcurrentSchedulingTest extends IntegrationTestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->bootWithFixtures();

        $this->workspace = sys_get_temp_dir().'/emc-concurrency-'.bin2hex(random_bytes(6));

        if (!mkdir($this->workspace, 0o777, true) && !is_dir($this->workspace)) {
            self::fail('The temporary directory of the concurrency test could not be created.');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workspace.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->workspace);
    }

    public function test_two_simultaneous_schedulings_of_the_same_room_cannot_both_succeed(): void
    {
        $em = $this->entityManager();

        $event = $em->getRepository(Event::class)->findOneBy([]);
        $layout = $em->getRepository(SeatingLayout::class)->findOneBy([]);

        self::assertInstanceOf(Event::class, $event);
        self::assertInstanceOf(SeatingLayout::class, $layout);

        // The parent must not hold a connection open: it would lock the
        // rows the children need.
        $em->getConnection()->close();

        $barrier = $this->workspace.'/barrier';
        $results = [];
        $processes = [];

        for ($i = 0; $i < 2; ++$i) {
            $resultFile = $this->workspace.'/result-'.$i;
            $readyFile = $this->workspace.'/ready-'.$i;

            $command = sprintf(
                '%s %s %s %s %s %d %d %s %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(dirname(__DIR__).'/Fixtures/schedule_performance.php'),
                escapeshellarg($resultFile),
                escapeshellarg($readyFile),
                escapeshellarg($barrier),
                (int) $event->getId(),
                (int) $layout->getId(),
                escapeshellarg('2027-02-20 20:00:00'),
                escapeshellarg('2027-02-20 22:00:00'),
            );

            $pipes = [];
            $process = proc_open(
                $command,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__, 2),
            );

            self::assertIsResource($process);

            $processes[$i] = [$process, $pipes];
            $results[$i] = $resultFile;
        }

        // Both children signal that they are ready…
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            $ready = 0;

            for ($i = 0; $i < 2; ++$i) {
                if (file_exists($this->workspace.'/ready-'.$i)) {
                    ++$ready;
                }
            }

            if (2 === $ready) {
                break;
            }

            usleep(10_000);
        }

        // …and the barrier releases them at the same instant.
        touch($barrier);

        $outputs = [];

        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $outputs[] = [proc_close($process), $stdout, $stderr];
        }

        $contents = array_map(
            static fn (string $file): string => is_file($file) ? (string) file_get_contents($file) : '',
            $results,
        );

        $successes = count(array_filter($contents, static fn (string $content): bool => 'OK' === $content));

        self::assertSame(
            1,
            $successes,
            sprintf(
                "Exactly one scheduling must succeed.\nResults: %s\nOutputs: %s",
                implode(' | ', $contents),
                implode(' | ', array_map(static fn (array $o): string => $o[1].$o[2], $outputs)),
            ),
        );

        // And the room holds a single performance.
        $em->getConnection()->close();
        $em = $this->entityManager();

        $performances = $em->getRepository(Performance::class)->findBy(['seatingLayout' => $layout]);

        self::assertCount(1, $performances);
    }
}
