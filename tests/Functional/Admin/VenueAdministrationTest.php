<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Place;
use App\Entity\Room;
use App\Entity\Venue;
use App\Tests\Functional\FunctionalTestCase;

/**
 * The administration of the physical venue: the venues, their rooms and the
 * places those rooms hold.
 */
class VenueAdministrationTest extends FunctionalTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
    }

    private function loginAsAdmin(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(\App\Entity\User::class)
            ->findOneBy(['email' => \App\DataFixtures\AppFixtures::USER_ADMIN_EMAIL]));

        return $this->client;
    }

    public function test_the_venues_are_listed(): void
    {
        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/venues');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Théâtre Al-Mansour');
        self::assertSelectorTextContains('tbody', '12, Avenue Mohammed V');
        // The number of rooms of the venue.
        self::assertSelectorTextContains('tbody', '1 room');
    }

    public function test_an_administrator_creates_a_venue(): void
    {
        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/venues/new');

        self::assertResponseIsSuccessful();

        $client->submit($crawler->filter('form')->form([
            'admin_venue[name]' => 'Salle Annexe',
            'admin_venue[address]' => '3, Rue des Palmiers',
            'admin_venue[description]' => 'Une seconde salle.',
        ]));

        self::assertResponseRedirects('/admin/venues');

        $created = $this->entityManager()->getRepository(Venue::class)
            ->findOneBy(['name' => 'Salle Annexe']);

        self::assertNotNull($created);
        self::assertSame('3, Rue des Palmiers', $created->getAddress());
    }

    public function test_a_venue_that_contains_rooms_cannot_be_deleted(): void
    {
        $client = $this->loginAsAdmin();
        $venue = $this->entityManager()->getRepository(Venue::class)->findOneBy([]);

        $crawler = $client->request('GET', '/admin/venues');

        $form = $crawler->filter('form[action*="/admin/venues/'.$venue->getId().'/delete"]')->form();
        $client->submit($form);

        self::assertResponseRedirects('/admin/venues');

        $client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'still contains');

        self::assertNotNull($this->entityManager()->getRepository(Venue::class)->find($venue->getId()));
    }

    public function test_an_administrator_creates_a_room(): void
    {
        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/rooms/new');

        self::assertResponseIsSuccessful();

        $venue = $this->entityManager()->getRepository(Venue::class)->findOneBy([]);

        $client->submit($crawler->filter('form')->form([
            'admin_room[venue]' => (string) $venue->getId(),
            'admin_room[name]' => 'Petit Studio',
            'admin_room[description]' => 'Studio de répétition.',
        ]));

        self::assertResponseRedirects('/admin/rooms');

        $created = $this->entityManager()->getRepository(Room::class)->findOneBy(['name' => 'Petit Studio']);

        self::assertNotNull($created);
        self::assertSame($venue->getId(), $created->getVenue()->getId());
    }

    public function test_a_room_that_holds_places_cannot_be_deleted(): void
    {
        $client = $this->loginAsAdmin();
        $room = $this->entityManager()->getRepository(Room::class)->findOneBy([]);

        $crawler = $client->request('GET', '/admin/rooms');

        $form = $crawler->filter('form[action*="/admin/rooms/'.$room->getId().'/delete"]')->form();
        $client->submit($form);

        self::assertResponseRedirects('/admin/rooms');

        $client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'still holds');

        self::assertNotNull($this->entityManager()->getRepository(Room::class)->find($room->getId()));
    }

    public function test_an_administrator_creates_a_place(): void
    {
        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/places/new');

        self::assertResponseIsSuccessful();

        $room = $this->entityManager()->getRepository(Room::class)->findOneBy([]);

        $client->submit($crawler->filter('form')->form([
            'admin_place[room]' => (string) $room->getId(),
            'admin_place[code]' => 'K-01',
            'admin_place[type]' => 'SEAT',
            'admin_place[rowLabel]' => 'K',
            'admin_place[seatNumber]' => '1',
            'admin_place[isActive]' => '1',
        ]));

        self::assertResponseRedirects('/admin/places');

        $created = $this->entityManager()->getRepository(Place::class)->findOneBy(['code' => 'K-01']);

        self::assertNotNull($created);
        self::assertSame('K', $created->getRowLabel());
    }

    public function test_a_place_cannot_reuse_the_code_of_another_place_of_its_room(): void
    {
        $client = $this->loginAsAdmin();
        $em = $this->entityManager();
        $room = $em->getRepository(Room::class)->findOneBy([]);

        $crawler = $client->request('GET', '/admin/places/new');

        $client->submit($crawler->filter('form')->form([
            'admin_place[room]' => (string) $room->getId(),
            'admin_place[code]' => 'A-01',
            'admin_place[type]' => 'SEAT',
            'admin_place[rowLabel]' => 'A',
            'admin_place[seatNumber]' => '99',
        ]));

        // The form comes back with the reason next to the field.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form__error', 'The code "A-01" is already used in the room "Grande Salle".');

        // And nothing was created.
        $em->clear();
        self::assertNull($em->getRepository(Place::class)->findOneBy(['code' => 'A-01', 'seatNumber' => 99]));
    }

    public function test_a_place_can_keep_its_own_code_when_it_is_edited(): void
    {
        $client = $this->loginAsAdmin();
        $em = $this->entityManager();
        $place = $em->getRepository(Place::class)->findOneBy(['code' => 'A-01']);

        $crawler = $client->request('GET', '/admin/places/'.$place->getId().'/edit');

        $client->submit($crawler->filter('form')->form([
            'admin_place[room]' => (string) $place->getRoom()->getId(),
            'admin_place[code]' => 'A-01',
            'admin_place[type]' => 'SEAT',
            'admin_place[rowLabel]' => 'A',
            'admin_place[seatNumber]' => '1',
        ]));

        self::assertResponseRedirects('/admin/places');
    }

    public function test_an_administrator_creates_a_block_of_places(): void
    {
        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/places/block');

        self::assertResponseIsSuccessful();

        $room = $this->entityManager()->getRepository(Room::class)->findOneBy([]);

        $client->submit($crawler->filter('form')->form([
            'admin_place_block[room]' => (string) $room->getId(),
            'admin_place_block[rowLabel]' => 'Z',
            'admin_place_block[from]' => '1',
            'admin_place_block[to]' => '5',
            'admin_place_block[type]' => 'SEAT',
            'admin_place_block[codePrefix]' => '',
        ]));

        self::assertResponseRedirects();

        $em = $this->entityManager();
        $created = $em->getRepository(Place::class)->findBy(['rowLabel' => 'Z']);

        self::assertCount(5, $created);
        self::assertSame('Z-01', $created[0]->getCode());
        self::assertSame('Z-05', $created[4]->getCode());
    }

    public function test_a_block_skips_the_places_that_already_exist(): void
    {
        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/places/block');

        $room = $this->entityManager()->getRepository(Room::class)->findOneBy([]);

        // Row A already holds the seats A-01 to A-40.
        $client->submit($crawler->filter('form')->form([
            'admin_place_block[room]' => (string) $room->getId(),
            'admin_place_block[rowLabel]' => 'A',
            'admin_place_block[from]' => '39',
            'admin_place_block[to]' => '42',
            'admin_place_block[type]' => 'SEAT',
            'admin_place_block[codePrefix]' => '',
        ]));

        self::assertResponseRedirects();

        $em = $this->entityManager();
        $row = $em->getRepository(Place::class)->findBy(['rowLabel' => 'A']);

        // A-39 and A-40 already existed; A-41 and A-42 were created.
        self::assertCount(42, $row);
        self::assertNotNull($em->getRepository(Place::class)->findOneBy(['code' => 'A-41']));
        self::assertNotNull($em->getRepository(Place::class)->findOneBy(['code' => 'A-42']));
    }

    public function test_a_place_mapped_in_a_layout_can_only_be_deactivated(): void
    {
        $client = $this->loginAsAdmin();
        $em = $this->entityManager();

        // The seats of the demo layout are mapped.
        $mapped = $em->getRepository(Place::class)->findOneBy(['code' => 'A-01']);

        $crawler = $client->request('GET', '/admin/places');

        // The delete button is not even offered for a mapped place.
        self::assertCount(0, $crawler->filter('form[action*="/admin/places/'.$mapped->getId().'/delete"]'));

        // And the server refuses it anyway.
        $client->request('POST', '/admin/places/'.$mapped->getId().'/delete', [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseRedirects('/admin/places');
        self::assertNotNull($em->getRepository(Place::class)->find($mapped->getId()));
    }

    public function test_a_place_can_be_taken_out_of_service(): void
    {
        $client = $this->loginAsAdmin();
        $em = $this->entityManager();
        $place = $em->getRepository(Place::class)->findOneBy(['code' => 'B-01']);

        // The screen shows the places a page at a time, so the page size is
        // widened to reach the seat of the second row.
        $crawler = $client->request('GET', '/admin/places?perPage=96');

        $form = $crawler->filter('form[action*="/admin/places/'.$place->getId().'/toggle"]')->form();
        $client->submit($form);

        self::assertResponseRedirects('/admin/places');

        $em->clear();
        self::assertFalse($em->getRepository(Place::class)->find($place->getId())->isActive());
    }

    public function test_a_customer_cannot_reach_the_venue_administration(): void
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(\App\Entity\User::class)
            ->findOneBy(['email' => \App\DataFixtures\AppFixtures::USER_CUSTOMER_EMAIL]));

        foreach (['/admin/venues', '/admin/rooms', '/admin/places'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }
    }
}
