<?php

namespace App\Tests\Functional\Admin;

use App\DataFixtures\AppFixtures;
use App\Entity\Event;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Entity\SeatingLayout;
use App\Entity\User;
use App\Enum\EventType;
use App\Tests\Functional\FunctionalTestCase;

/**
 * The administration of the events and their performances.
 */
class EventAdministrationTest extends FunctionalTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]));
    }

    private function layout(): SeatingLayout
    {
        $layout = $this->entityManager()->getRepository(SeatingLayout::class)->findOneBy([]);

        self::assertInstanceOf(SeatingLayout::class, $layout);

        return $layout;
    }

    public function test_the_events_are_listed(): void
    {
        $crawler = $this->client->request('GET', '/admin/events');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Soirée de gala');
    }

    public function test_an_administrator_creates_an_event(): void
    {
        $crawler = $this->client->request('GET', '/admin/events/new');

        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form')->form([
            'admin_event[title]' => 'Nuit de la musique',
            'admin_event[type]' => 'CONCERT',
            'admin_event[description]' => 'Un concert de gala.',
        ]));

        self::assertResponseRedirects();

        $created = $this->entityManager()->getRepository(Event::class)
            ->findOneBy(['title' => 'Nuit de la musique']);

        self::assertNotNull($created);
        self::assertSame(EventType::Concert, $created->getType());
        self::assertSame('DRAFT', $created->getStatus()->value);
    }

    public function test_an_administrator_schedules_a_performance(): void
    {
        $event = $this->entityManager()->getRepository(Event::class)->findOneBy([]);
        $layout = $this->layout();

        $crawler = $this->client->request('GET', '/admin/events/'.$event->getId().'/performances/new');

        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form')->form([
            'admin_performance[layout]' => (string) $layout->getId(),
            'admin_performance[startsAt]' => '2027-03-14T20:00',
        ]));

        self::assertResponseRedirects('/admin/events/'.$event->getId());

        $created = $this->entityManager()->getRepository(Performance::class)
            ->findOneBy(['event' => $event], ['id' => 'DESC']);

        self::assertNotNull($created);
        self::assertSame($layout->getId(), $created->getSeatingLayout()->getId());
        self::assertSame('2027-03-14', $created->getStartsAt()->format('Y-m-d'));
    }

    public function test_the_inventory_is_generated_from_the_configuration(): void
    {
        $event = $this->entityManager()->getRepository(Event::class)->findOneBy([]);
        $layout = $this->layout();

        $crawler = $this->client->request('GET', '/admin/events/'.$event->getId().'/performances/new');

        $this->client->submit($crawler->filter('form')->form([
            'admin_performance[layout]' => (string) $layout->getId(),
            'admin_performance[startsAt]' => '2027-04-01T20:00',
        ]));

        self::assertResponseRedirects();

        $performance = $this->entityManager()->getRepository(Performance::class)
            ->findOneBy(['event' => $event], ['id' => 'DESC']);

        self::assertNotNull($performance);

        // One sellable seat per seat of the configuration.
        $inventory = $this->entityManager()->getRepository(PerformancePlace::class)
            ->findBy(['performance' => $performance]);

        self::assertCount(400, $inventory);
        self::assertSame('AVAILABLE', $inventory[0]->getStatus()->value);
    }

    public function test_the_publication_checks_are_shown(): void
    {
        $event = $this->entityManager()->getRepository(Event::class)->findOneBy([]);

        $crawler = $this->client->request('GET', '/admin/events/'.$event->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.checklist', 'The event has a performance');
        self::assertSelectorTextContains('.checklist', 'The inventory is generated');
        self::assertSelectorTextContains('.checklist', 'The prices are set');
    }

    public function test_an_event_without_a_performance_cannot_be_published(): void
    {
        $em = $this->entityManager();
        $event = new Event('Sans représentation', EventType::Other);
        $em->persist($event);
        $em->flush();

        $crawler = $this->client->request('GET', '/admin/events/'.$event->getId());

        $form = $crawler->filter('form[action*="/publish"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/events/'.$event->getId());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'cannot be published');

        $em->clear();
        self::assertSame('DRAFT', $em->getRepository(Event::class)->find($event->getId())->getStatus()->value);
    }

    public function test_an_event_is_archived(): void
    {
        $em = $this->entityManager();
        $event = $em->getRepository(Event::class)->findOneBy([]);

        // A published event can be archived.
        $event->setStatus(\App\Enum\EventStatus::Published);
        $em->flush();

        $crawler = $this->client->request('GET', '/admin/events/'.$event->getId());

        $form = $crawler->filter('form[action*="/archive"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/events/'.$event->getId());

        $em->clear();
        self::assertSame('ARCHIVED', $em->getRepository(Event::class)->find($event->getId())->getStatus()->value);
    }

    public function test_a_performance_is_cancelled(): void
    {
        $event = $this->entityManager()->getRepository(Event::class)->findOneBy([]);
        $performance = new Performance($event, $this->layout(), new \DateTimeImmutable('2027-05-05T20:00'));
        $this->entityManager()->persist($performance);
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/events/'.$event->getId());

        $form = $crawler->filter('form[action*="/performances/'.$performance->getId().'/cancel"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/events/'.$event->getId());

        $em = $this->entityManager();
        $em->clear();
        self::assertSame('CANCELLED', $em->getRepository(Performance::class)->find($performance->getId())->getStatus()->value);
    }

    public function test_deleting_a_performance_deletes_its_inventory(): void
    {
        $em = $this->entityManager();
        $event = $em->getRepository(Event::class)->findOneBy([]);
        $layout = $this->layout();

        $performance = new Performance($event, $layout, new \DateTimeImmutable('2027-06-06T20:00'));
        $em->persist($performance);
        $em->flush();

        $place = $em->getRepository(\App\Entity\LayoutPlace::class)->findOneBy([]);
        $em->persist(new PerformancePlace($performance, $place));
        $em->flush();

        $crawler = $this->client->request('GET', '/admin/events/'.$event->getId());

        $form = $crawler->filter('form[action*="/performances/'.$performance->getId().'/delete"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/events/'.$event->getId());

        $em->clear();

        self::assertNull($em->getRepository(Performance::class)->find($performance->getId()));
        self::assertCount(0, $em->getRepository(PerformancePlace::class)->findBy(['performance' => $performance]));
    }

    public function test_an_event_that_has_a_performance_cannot_be_deleted(): void
    {
        $em = $this->entityManager();
        $event = $em->getRepository(Event::class)->findOneBy([]);

        // The event gets a performance.
        $em->persist(new Performance($event, $this->layout(), new \DateTimeImmutable('2027-07-07T20:00')));
        $em->flush();

        $crawler = $this->client->request('GET', '/admin/events');

        $form = $crawler->filter('form[action*="/admin/events/'.$event->getId().'/delete"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/events');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'This event has 1 performance');

        $em->clear();

        // The event survives.
        self::assertNotNull($em->getRepository(Event::class)->find($event->getId()));
    }

    public function test_an_event_without_a_performance_can_be_deleted(): void
    {
        $em = $this->entityManager();
        $event = new Event('Sans représentation', EventType::Other);
        $em->persist($event);
        $em->flush();

        $crawler = $this->client->request('GET', '/admin/events');

        $form = $crawler->filter('form[action*="/admin/events/'.$event->getId().'/delete"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/events');

        $em->clear();
        self::assertNull($em->getRepository(Event::class)->find($event->getId()));
    }

    public function test_a_customer_cannot_reach_the_event_administration(): void
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]));

        foreach (['/admin/events', '/admin/events/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }
    }
}
