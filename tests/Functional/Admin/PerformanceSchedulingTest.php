<?php

namespace App\Tests\Functional\Admin;

use App\DataFixtures\AppFixtures;
use App\Entity\LayoutPlace;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Entity\User;
use App\Entity\Venue;
use App\Exception\InvalidPerformanceScheduleException;
use App\Exception\PerformanceRoomUnavailableException;
use App\Service\Event\PerformanceService;
use App\Tests\Functional\FunctionalTestCase;

/**
 * The scheduling of the performances: the room is booked, not the
 * configuration.
 *
 * A room cannot host two overlapping performances, whichever configuration
 * they use.
 */
class PerformanceSchedulingTest extends FunctionalTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]));
    }

    private function service(): PerformanceService
    {
        /** @var PerformanceService $service */
        $service = static::getContainer()->get(PerformanceService::class);

        return $service;
    }

    private function layout(): SeatingLayout
    {
        $layout = $this->entityManager()->getRepository(SeatingLayout::class)->findOneBy([]);

        self::assertInstanceOf(SeatingLayout::class, $layout);

        return $layout;
    }

    private function event(): \App\Entity\Event
    {
        $event = $this->entityManager()->getRepository(\App\Entity\Event::class)->findOneBy([]);

        self::assertInstanceOf(\App\Entity\Event::class, $event);

        return $event;
    }

    /**
     * A second room of the venue, with its own configuration.
     */
    private function otherRoomWithLayout(): SeatingLayout
    {
        $em = $this->entityManager();
        $venue = $em->getRepository(Venue::class)->findOneBy([]);

        $room = new Room($venue, 'Petit Studio');
        $em->persist($room);
        $em->flush();

        $layout = new SeatingLayout($room, 'Configuration Studio', 1);
        $em->persist($layout);
        $em->flush();

        return $layout;
    }

    public function test_two_overlapping_performances_of_the_same_layout_are_rejected(): void
    {
        $layout = $this->layout();

        $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            new \DateTimeImmutable('2027-01-10 14:00:00'),
        );

        $this->expectException(PerformanceRoomUnavailableException::class);
        $this->expectExceptionMessage('is already occupied by');

        $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 13:00:00'),
            new \DateTimeImmutable('2027-01-10 15:00:00'),
        );
    }

    public function test_two_overlapping_performances_of_different_layouts_of_the_same_room_are_rejected(): void
    {
        $layout = $this->layout();

        // A second configuration of the **same** room.
        $other = new SeatingLayout($layout->getRoom(), 'Configuration Concert', 2);
        $this->entityManager()->persist($other);
        $this->entityManager()->flush();

        $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            new \DateTimeImmutable('2027-01-10 14:00:00'),
        );

        $this->expectException(PerformanceRoomUnavailableException::class);

        $this->service()->create(
            $this->event(),
            $other,
            new \DateTimeImmutable('2027-01-10 13:00:00'),
            new \DateTimeImmutable('2027-01-10 15:00:00'),
        );
    }

    public function test_overlapping_performances_in_two_different_rooms_are_allowed(): void
    {
        $other = $this->otherRoomWithLayout();

        $this->service()->create(
            $this->event(),
            $this->layout(),
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            new \DateTimeImmutable('2027-01-10 14:00:00'),
        );

        $performance = $this->service()->create(
            $this->event(),
            $other,
            new \DateTimeImmutable('2027-01-10 13:00:00'),
            new \DateTimeImmutable('2027-01-10 15:00:00'),
        );

        self::assertSame($other->getId(), $performance->getSeatingLayout()->getId());
    }

    public function test_back_to_back_performances_are_allowed(): void
    {
        $layout = $this->layout();

        $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            new \DateTimeImmutable('2027-01-10 14:00:00'),
        );

        $performance = $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 14:00:00'),
            new \DateTimeImmutable('2027-01-10 16:00:00'),
        );

        self::assertSame('2027-01-10 14:00:00', $performance->getStartsAt()->format('Y-m-d H:i:s'));
    }

    public function test_a_cancelled_performance_does_not_block_the_room(): void
    {
        $layout = $this->layout();

        $cancelled = $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            new \DateTimeImmutable('2027-01-10 14:00:00'),
        );

        $this->service()->cancel($cancelled);

        // The room is free again.
        $performance = $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 13:00:00'),
            new \DateTimeImmutable('2027-01-10 15:00:00'),
        );

        self::assertSame('2027-01-10 13:00:00', $performance->getStartsAt()->format('Y-m-d H:i:s'));
    }

    public function test_updating_a_performance_into_a_conflict_is_rejected(): void
    {
        $layout = $this->layout();

        $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            new \DateTimeImmutable('2027-01-10 14:00:00'),
        );

        $moved = $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 16:00:00'),
            new \DateTimeImmutable('2027-01-10 18:00:00'),
        );

        $this->expectException(PerformanceRoomUnavailableException::class);

        $this->service()->update(
            $moved,
            new \DateTimeImmutable('2027-01-10 13:00:00'),
            new \DateTimeImmutable('2027-01-10 17:00:00'),
            null,
            null,
        );
    }

    public function test_updating_a_performance_does_not_conflict_with_itself(): void
    {
        $layout = $this->layout();

        $performance = $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            new \DateTimeImmutable('2027-01-10 14:00:00'),
        );

        // The same interval, and then a new one that does not overlap.
        $this->service()->update(
            $performance,
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            new \DateTimeImmutable('2027-01-10 14:00:00'),
            null,
            null,
        );

        $this->service()->update(
            $performance,
            new \DateTimeImmutable('2027-01-10 16:00:00'),
            new \DateTimeImmutable('2027-01-10 18:00:00'),
            null,
            null,
        );

        self::assertSame('2027-01-10 16:00:00', $performance->getStartsAt()->format('Y-m-d H:i:s'));
    }

    public function test_an_end_datetime_that_is_not_after_the_start_is_rejected(): void
    {
        $this->expectException(InvalidPerformanceScheduleException::class);
        $this->expectExceptionMessage('before it starts');

        $this->service()->create(
            $this->event(),
            $this->layout(),
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            new \DateTimeImmutable('2027-01-10 12:00:00'),
        );
    }

    public function test_a_sales_closing_after_the_start_is_rejected(): void
    {
        $this->expectException(InvalidPerformanceScheduleException::class);
        $this->expectExceptionMessage('The sale closes');

        $this->service()->create(
            $this->event(),
            $this->layout(),
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            new \DateTimeImmutable('2027-01-10 14:00:00'),
            null,
            new \DateTimeImmutable('2027-01-10 13:00:00'),
        );
    }

    public function test_a_performance_crossing_midnight_is_accepted_with_an_explicit_date(): void
    {
        $performance = $this->service()->create(
            $this->event(),
            $this->layout(),
            new \DateTimeImmutable('2027-01-10 23:00:00'),
            new \DateTimeImmutable('2027-01-11 01:00:00'),
        );

        self::assertSame('2027-01-11 01:00:00', $performance->getEndsAt()->format('Y-m-d H:i:s'));
    }

    public function test_a_performance_without_an_end_date_blocks_the_room(): void
    {
        $layout = $this->layout();

        $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 12:00:00'),
        );

        $this->expectException(PerformanceRoomUnavailableException::class);
        $this->expectExceptionMessage('no end date');

        $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 13:00:00'),
            new \DateTimeImmutable('2027-01-10 15:00:00'),
        );
    }

    public function test_a_performance_before_an_open_ended_one_is_allowed(): void
    {
        $layout = $this->layout();

        $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 12:00:00'),
        );

        // The open-ended show starts at noon: a show that ends before noon
        // does not overlap it.
        $performance = $this->service()->create(
            $this->event(),
            $layout,
            new \DateTimeImmutable('2027-01-10 09:00:00'),
            new \DateTimeImmutable('2027-01-10 11:00:00'),
        );

        self::assertSame('2027-01-10 09:00:00', $performance->getStartsAt()->format('Y-m-d H:i:s'));
    }

    public function test_the_conflict_is_reported_to_the_administrator(): void
    {
        $layout = $this->layout();
        $event = $this->event();

        $this->service()->create(
            $event,
            $layout,
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            new \DateTimeImmutable('2027-01-10 14:00:00'),
        );

        $crawler = $this->client->request('GET', '/admin/events/'.$event->getId().'/performances/new');

        $form = $crawler->filter('form')->form([
            'admin_performance[layout]' => (string) $layout->getId(),
            'admin_performance[startsAt]' => '2027-01-10T13:00',
            'admin_performance[endsAt]' => '2027-01-10T15:00',
        ]);

        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert--danger', 'is already occupied by');
        self::assertSelectorTextContains('.alert--danger', $layout->getRoom()->getName());
    }

    public function test_the_layout_selector_shows_the_room(): void
    {
        $layout = $this->layout();

        $crawler = $this->client->request('GET', '/admin/events/'.$this->event()->getId().'/performances/new');

        $options = $crawler->filter('select[name="admin_performance[layout]"] option')->each(
            static fn ($node): string => trim($node->text()),
        );

        self::assertContains(
            sprintf('%s v%d — %s', $layout->getName(), $layout->getVersion(), $layout->getRoom()->getName()),
            $options,
        );
    }
}
