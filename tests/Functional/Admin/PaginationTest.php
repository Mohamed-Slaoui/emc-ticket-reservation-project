<?php

namespace App\Tests\Functional\Admin;

use App\DataFixtures\AppFixtures;
use App\Entity\Room;
use App\Entity\User;
use App\Entity\Venue;
use App\Tests\Functional\FunctionalTestCase;

/**
 * The pagination of the list screens: the page size, the navigation, and
 * the way the filters and the page size survive a move to another page.
 */
class PaginationTest extends FunctionalTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]));
    }

    public function test_the_places_are_shown_six_at_a_time_by_default(): void
    {
        $crawler = $this->client->request('GET', '/admin/places');

        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('.pagination__summary', '1–6 of 400');
    }

    public function test_the_visitor_can_choose_the_number_of_rows(): void
    {
        $crawler = $this->client->request('GET', '/admin/places?perPage=24');

        self::assertResponseIsSuccessful();
        self::assertCount(24, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('.pagination__summary', '1–24 of 400');

        // The selector shows the size in use.
        self::assertSame('24', $crawler->filter('.page-size__select option[selected]')->attr('value'));
    }

    public function test_the_page_size_is_offered_next_to_the_filter(): void
    {
        $crawler = $this->client->request('GET', '/admin/places');

        // The selector lives inside the filter form, so it is submitted with
        // the other filters.
        self::assertCount(1, $crawler->filter('form.list-toolbar .page-size__select'));
        self::assertSame('perPage', $crawler->filter('.page-size__select')->attr('name'));

        $choices = $crawler->filter('.page-size__select option');
        self::assertCount(5, $choices);
        self::assertSame('6', $choices->eq(0)->attr('value'));
    }

    public function test_a_page_size_that_is_not_allowed_falls_back_to_the_default(): void
    {
        $crawler = $this->client->request('GET', '/admin/places?perPage=9999');

        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('tbody tr'));
    }

    public function test_the_navigation_keeps_the_page_size(): void
    {
        $crawler = $this->client->request('GET', '/admin/places?perPage=24');

        $next = $crawler->filter('.pagination__page[rel="next"]')->attr('href');

        self::assertStringContainsString('perPage=24', (string) $next);
        self::assertStringContainsString('page=2', (string) $next);
    }

    public function test_the_navigation_keeps_the_filter(): void
    {
        $room = $this->entityManager()->getRepository(Room::class)->findOneBy([]);

        $crawler = $this->client->request('GET', '/admin/places?room='.$room->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('tbody tr'));

        $next = $crawler->filter('.pagination__page[rel="next"]')->attr('href');

        self::assertStringContainsString('room='.$room->getId(), (string) $next);
    }

    public function test_the_second_page_shows_the_next_rows(): void
    {
        $crawler = $this->client->request('GET', '/admin/places?page=2');

        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('.pagination__summary', '7–12 of 400');
    }

    public function test_a_page_beyond_the_last_one_falls_back_to_the_last_page(): void
    {
        $crawler = $this->client->request('GET', '/admin/places?page=9999');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pagination__summary', '397–400 of 400');
    }

    public function test_the_rooms_are_paginated(): void
    {
        $crawler = $this->client->request('GET', '/admin/rooms');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('.pagination__summary', '1–1 of 1');
    }

    public function test_the_venues_are_paginated(): void
    {
        $crawler = $this->client->request('GET', '/admin/venues');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('.pagination__summary', '1–1 of 1');
    }

    public function test_the_accounts_are_paginated(): void
    {
        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('.pagination__summary', '1–3 of 3');
    }

    public function test_the_navigation_of_the_accounts_keeps_the_search(): void
    {
        $crawler = $this->client->request('GET', '/admin/users?search=Fatima');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody tr'));

        // A single result: no navigation, but the size is still offered.
        self::assertCount(1, $crawler->filter('.page-size__select'));
    }

    public function test_an_empty_filter_does_not_break_the_screen(): void
    {
        // The filter form submits an empty value when "every room" is
        // chosen. Symfony 7.4 throws on an empty integer parameter, so the
        // screen has to read it as a string.
        foreach (['/admin/places?room=', '/admin/rooms?venue=', '/admin/places?perPage=', '/admin/places?page='] as $url) {
            $this->client->request('GET', $url);

            self::assertResponseIsSuccessful($url);
        }
    }

    public function test_the_places_of_a_filtered_room_are_counted_alone(): void
    {
        $venue = $this->entityManager()->getRepository(Venue::class)->findOneBy([]);
        $room = $this->entityManager()->getRepository(Room::class)->findOneBy([]);

        $crawler = $this->client->request('GET', '/admin/places?room='.$room->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pagination__summary', 'of 400');
        self::assertSame('Grande Salle', $venue->getRooms()->first()->getName());
    }
}
