<?php

namespace App\Tests\Functional\Admin;

use App\DataFixtures\AppFixtures;
use App\Entity\LayoutPlace;
use App\Entity\LayoutZone;
use App\Entity\Performance;
use App\Entity\Place;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\EventType;
use App\Entity\Event;
use App\Tests\Functional\FunctionalTestCase;

/**
 * The administration of the seating layouts: the versioned configurations
 * of a room, their zones and their seat map.
 *
 * The demo data holds one configuration: rows A–F in the zone ORC (240
 * seats), G–H in MEZ (80) and I–J in BAL (80).
 */
class LayoutAdministrationTest extends FunctionalTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]));
    }

    private function room(): Room
    {
        $room = $this->entityManager()->getRepository(Room::class)->findOneBy([]);

        self::assertInstanceOf(Room::class, $room);

        return $room;
    }

    private function layout(): SeatingLayout
    {
        $layout = $this->entityManager()->getRepository(SeatingLayout::class)->findOneBy([]);

        self::assertInstanceOf(SeatingLayout::class, $layout);

        return $layout;
    }

    private function zone(string $code): LayoutZone
    {
        $zone = $this->entityManager()->getRepository(LayoutZone::class)->findOneBy(['code' => $code]);

        self::assertInstanceOf(LayoutZone::class, $zone);

        return $zone;
    }

    /**
     * The CSRF token of a form of the page, read the way a browser reads it.
     *
     * @param string $action The fragment of the action of the form
     */
    private function tokenOf(string $action, string $url): string
    {
        $crawler = $this->client->request('GET', $url);

        $token = $crawler->filter('form[action*="'.$action.'"] input[name="_token"]')->first()->attr('value');

        self::assertIsString($token);

        return $token;
    }

    public function test_the_layouts_are_listed(): void
    {
        $crawler = $this->client->request('GET', '/admin/layouts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Configuration Standard');
        self::assertSelectorTextContains('tbody', 'v1');
    }

    public function test_an_administrator_creates_a_layout(): void
    {
        $crawler = $this->client->request('GET', '/admin/layouts/new');

        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form')->form([
            'admin_seating_layout[room]' => (string) $this->room()->getId(),
            'admin_seating_layout[name]' => 'Concert',
            'admin_seating_layout[description]' => 'Configuration for the concerts.',
        ]));

        self::assertResponseRedirects();

        $created = $this->entityManager()->getRepository(SeatingLayout::class)
            ->findOneBy(['name' => 'Concert']);

        self::assertNotNull($created);
        // The demo configuration already uses the version 1 of the room.
        self::assertSame(2, $created->getVersion());
    }

    public function test_an_administrator_creates_a_zone(): void
    {
        $layout = $this->layout();

        $crawler = $this->client->request('GET', '/admin/layouts/'.$layout->getId().'/zones/new');

        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form')->form([
            'admin_layout_zone[code]' => 'PREMIUM',
            'admin_layout_zone[name]' => 'Premium',
            'admin_layout_zone[description]' => 'The best seats.',
        ]));

        self::assertResponseRedirects('/admin/layouts/'.$layout->getId());

        $created = $this->entityManager()->getRepository(LayoutZone::class)
            ->findOneBy(['code' => 'PREMIUM']);

        self::assertNotNull($created);
        self::assertSame('Premium', $created->getName());
    }

    public function test_a_zone_code_cannot_be_reused_in_the_same_layout(): void
    {
        $layout = $this->layout();

        $crawler = $this->client->request('GET', '/admin/layouts/'.$layout->getId().'/zones/new');

        $this->client->submit($crawler->filter('form')->form([
            'admin_layout_zone[code]' => 'ORC',
            'admin_layout_zone[name]' => 'Another zone',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form__error', 'is already used by another zone');
    }

    public function test_an_administrator_maps_the_seats_to_a_zone(): void
    {
        $em = $this->entityManager();
        $layout = $this->layout();
        $orc = $this->zone('ORC');
        $mez = $this->zone('MEZ');

        // Two seats of the row G, which belong to the mezzanine.
        $places = $em->getRepository(Place::class)->findBy(['rowLabel' => 'G'], ['seatNumber' => 'ASC'], 2);

        self::assertCount(2, $places);

        $this->client->request('POST', '/admin/layouts/'.$layout->getId().'/places/assign', [
            '_token' => $this->tokenOf('/places/assign', '/admin/layouts/'.$layout->getId()),
            'places' => array_map(static fn (Place $place): int => (int) $place->getId(), $places),
            'zone' => (string) $orc->getId(),
            'action' => 'assign',
        ]);

        self::assertResponseRedirects('/admin/layouts/'.$layout->getId());

        $em->clear();

        self::assertCount(242, $em->getRepository(LayoutPlace::class)->findBy(['layoutZone' => $orc]));
        self::assertCount(78, $em->getRepository(LayoutPlace::class)->findBy(['layoutZone' => $mez]));
    }

    public function test_a_request_without_a_valid_token_maps_nothing(): void
    {
        $em = $this->entityManager();
        $layout = $this->layout();
        $orc = $this->zone('ORC');
        $places = $em->getRepository(Place::class)->findBy(['rowLabel' => 'G'], ['seatNumber' => 'ASC'], 2);

        $this->client->request('POST', '/admin/layouts/'.$layout->getId().'/places/assign', [
            '_token' => 'invalid-token',
            'places' => array_map(static fn (Place $place): int => (int) $place->getId(), $places),
            'zone' => (string) $orc->getId(),
            'action' => 'assign',
        ]);

        self::assertResponseRedirects('/admin/layouts/'.$layout->getId());

        $em->clear();

        self::assertCount(240, $em->getRepository(LayoutPlace::class)->findBy(['layoutZone' => $orc]));
    }

    public function test_a_seat_can_be_released(): void
    {
        $em = $this->entityManager();
        $layout = $this->layout();
        $mez = $this->zone('MEZ');

        $layoutPlace = $em->getRepository(LayoutPlace::class)->findOneBy(['layoutZone' => $mez]);

        self::assertNotNull($layoutPlace);

        $this->client->request('POST', '/admin/layouts/'.$layout->getId().'/places/assign', [
            '_token' => $this->tokenOf('/places/assign', '/admin/layouts/'.$layout->getId()),
            'places' => [(int) $layoutPlace->getPlace()->getId()],
            'action' => 'release',
        ]);

        self::assertResponseRedirects('/admin/layouts/'.$layout->getId());

        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--success', '1 seat released');

        $em->clear();

        // The seat leaves the configuration — the physical place is kept.
        self::assertNull($em->getRepository(LayoutPlace::class)->find($layoutPlace->getId()));
        self::assertCount(79, $em->getRepository(LayoutPlace::class)->findBy(['layoutZone' => $mez]));
    }

    public function test_deleting_a_zone_unmaps_its_seats(): void
    {
        $em = $this->entityManager();
        $layout = $this->layout();
        $bal = $this->zone('BAL');

        $this->client->request('POST', '/admin/layouts/'.$layout->getId().'/zones/'.$bal->getId().'/delete', [
            '_token' => $this->tokenOf('/zones/'.$bal->getId().'/delete', '/admin/layouts/'.$layout->getId()),
        ]);

        self::assertResponseRedirects('/admin/layouts/'.$layout->getId());

        $em->clear();

        self::assertNull($em->getRepository(LayoutZone::class)->find($bal->getId()));
        // The seats of the zone are unmapped, not deleted.
        self::assertCount(320, $em->getRepository(LayoutPlace::class)->findBy(['seatingLayout' => $layout]));
    }

    public function test_the_seat_map_shows_every_seat_of_the_room(): void
    {
        $layout = $this->layout();

        $crawler = $this->client->request('GET', '/admin/layouts/'.$layout->getId());

        self::assertResponseIsSuccessful();
        // The room holds 400 seats.
        self::assertCount(400, $crawler->filter('.seat__input'));
        self::assertSelectorTextContains('.page-header__subtitle', 'Version 1');
    }

    public function test_a_new_version_is_created(): void
    {
        $layout = $this->layout();

        $this->client->request('POST', '/admin/layouts/'.$layout->getId().'/new-version', [
            '_token' => $this->tokenOf('/new-version', '/admin/layouts/'.$layout->getId()),
        ]);

        self::assertResponseRedirects();

        $versions = $this->entityManager()->getRepository(SeatingLayout::class)
            ->findBy(['room' => $layout->getRoom()], ['version' => 'ASC']);

        self::assertCount(2, $versions);
        self::assertSame(2, $versions[1]->getVersion());
    }

    public function test_a_version_with_its_zones_and_its_seats_can_be_deleted(): void
    {
        $em = $this->entityManager();
        $layout = $this->layout();

        // A second version, with a zone of its own.
        $clone = new SeatingLayout($layout->getRoom(), 'Concert', 2);
        $em->persist($clone);
        $em->flush();

        $zone = new LayoutZone($clone, 'PREM', 'Premium');
        $em->persist($zone);
        $em->flush();

        $place = $em->getRepository(Place::class)->findOneBy([]);
        $em->persist(new LayoutPlace($clone, $zone, $place, 0));
        $em->flush();

        $this->client->request('POST', '/admin/layouts/'.$clone->getId().'/delete', [
            '_token' => $this->tokenOf('/admin/layouts/'.$clone->getId().'/delete', '/admin/layouts/'.$clone->getId()),
        ]);

        self::assertResponseRedirects('/admin/layouts');

        $em->clear();

        // The configuration, its zone and its seat map are gone.
        self::assertNull($em->getRepository(SeatingLayout::class)->find($clone->getId()));
        self::assertNull($em->getRepository(LayoutZone::class)->find($zone->getId()));
        self::assertCount(0, $em->getRepository(LayoutPlace::class)->findBy(['seatingLayout' => $clone]));

        // The physical place and the other configuration are untouched.
        self::assertNotNull($em->getRepository(Place::class)->find($place->getId()));
        self::assertNotNull($em->getRepository(SeatingLayout::class)->find($layout->getId()));
    }

    public function test_a_room_that_has_a_configuration_is_deleted_with_it(): void
    {
        $em = $this->entityManager();
        $venue = $em->getRepository(Venue::class)->findOneBy([]);

        // A room with no seat, but with a configuration.
        $room = new Room($venue, 'Studio sans places');
        $em->persist($room);
        $em->flush();

        $layout = new SeatingLayout($room, 'Standard', 1);
        $em->persist($layout);
        $em->flush();

        $crawler = $this->client->request('GET', '/admin/rooms');

        $form = $crawler->filter('form[action*="/admin/rooms/'.$room->getId().'/delete"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/rooms');

        $em->clear();

        // The room and its configuration are gone.
        self::assertNull($em->getRepository(Room::class)->find($room->getId()));
        self::assertNull($em->getRepository(SeatingLayout::class)->find($layout->getId()));
    }

    public function test_a_room_that_has_a_configuration_used_by_a_performance_cannot_be_deleted(): void
    {
        $em = $this->entityManager();
        $venue = $em->getRepository(Venue::class)->findOneBy([]);

        $room = new Room($venue, 'Studio des representations');
        $em->persist($room);
        $em->flush();

        $layout = new SeatingLayout($room, 'Standard', 1);
        $em->persist($layout);
        $em->flush();

        // A performance now refers to the configuration.
        $event = new Event('Concert de test', EventType::Concert);
        $em->persist($event);
        $em->persist(new Performance($event, $layout, new \DateTimeImmutable('+7 days')));
        $em->flush();

        $crawler = $this->client->request('GET', '/admin/rooms');

        $form = $crawler->filter('form[action*="/admin/rooms/'.$room->getId().'/delete"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/rooms');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'used by a performance');

        $em->clear();

        // The room and its configuration survive: the sale must too.
        self::assertNotNull($em->getRepository(Room::class)->find($room->getId()));
        self::assertNotNull($em->getRepository(SeatingLayout::class)->find($layout->getId()));
    }

    public function test_a_layout_used_by_a_performance_is_frozen(): void
    {
        $em = $this->entityManager();
        $layout = $this->layout();

        // A performance now refers to the configuration.
        $event = new Event('Concert de test', EventType::Concert);
        $em->persist($event);
        $em->persist(new Performance($event, $layout, new \DateTimeImmutable('+7 days')));
        $em->flush();

        // The editor says the configuration is frozen.
        $crawler = $this->client->request('GET', '/admin/layouts/'.$layout->getId());

        self::assertSelectorTextContains('.alert--warning', 'This configuration is frozen.');

        // A zone cannot be created any more.
        $crawler = $this->client->request('GET', '/admin/layouts/'.$layout->getId().'/zones/new');

        $this->client->submit($crawler->filter('form')->form([
            'admin_layout_zone[code]' => 'NEWZONE',
            'admin_layout_zone[name]' => 'New zone',
        ]));

        self::assertResponseRedirects('/admin/layouts/'.$layout->getId());

        $em->clear();
        self::assertNull($em->getRepository(LayoutZone::class)->findOneBy(['code' => 'NEWZONE']));

        // And the seats cannot be moved.
        $place = $em->getRepository(Place::class)->findOneBy(['rowLabel' => 'G']);
        $zone = $em->getRepository(LayoutZone::class)->findOneBy(['code' => 'ORC']);

        $this->client->request('POST', '/admin/layouts/'.$layout->getId().'/places/assign', [
            '_token' => $this->tokenOf('/places/assign', '/admin/layouts/'.$layout->getId()),
            'places' => [(int) $place->getId()],
            'zone' => (string) $zone->getId(),
            'action' => 'assign',
        ]);

        self::assertResponseRedirects('/admin/layouts/'.$layout->getId());

        $em->clear();
        // The seat of the row G did not move: the zone ORC still holds its 240 seats.
        self::assertCount(240, $em->getRepository(LayoutPlace::class)->findBy(['layoutZone' => $zone]));
    }

    public function test_a_customer_cannot_reach_the_layout_administration(): void
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]));

        foreach (['/admin/layouts', '/admin/layouts/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }
    }
}
