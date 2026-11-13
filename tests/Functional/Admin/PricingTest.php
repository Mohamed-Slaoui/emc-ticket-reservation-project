<?php

namespace App\Tests\Functional\Admin;

use App\DataFixtures\AppFixtures;
use App\Entity\Event;
use App\Entity\LayoutZone;
use App\Entity\Performance;
use App\Entity\PerformancePrice;
use App\Entity\SeatingLayout;
use App\Entity\Tariff;
use App\Entity\User;
use App\Enum\EventType;
use App\Tests\Functional\FunctionalTestCase;

/**
 * The pricing of a performance: the taxes, the versions, the publication.
 */
class PricingTest extends FunctionalTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]));
    }

    private function event(): Event
    {
        $event = $this->entityManager()->getRepository(Event::class)->findOneBy([]);

        self::assertInstanceOf(Event::class, $event);

        return $event;
    }

    private function layout(): SeatingLayout
    {
        $layout = $this->entityManager()->getRepository(SeatingLayout::class)->findOneBy([]);

        self::assertInstanceOf(SeatingLayout::class, $layout);

        return $layout;
    }

    private function performance(): Performance
    {
        $em = $this->entityManager();
        $event = new Event('Matinée théâtre', EventType::Theatre);
        $em->persist($event);
        $em->flush();

        /** @var \App\Service\Event\PerformanceService $service */
        $service = static::getContainer()->get(\App\Service\Event\PerformanceService::class);

        return $service->create(
            $event,
            $this->layout(),
            new \DateTimeImmutable('2027-08-08T20:00:00'),
        );
    }

    private function zoneIds(): array
    {
        $zones = $this->entityManager()->getRepository(LayoutZone::class)->findAll();

        return array_map(static fn (LayoutZone $zone): int => (int) $zone->getId(), $zones);
    }

    private function tariffIds(): array
    {
        $tariffs = $this->entityManager()->getRepository(Tariff::class)->findAll();

        return array_map(static fn (Tariff $tariff): int => (int) $tariff->getId(), $tariffs);
    }

    /**
     * A full matrix, with an amount for every zone × tariff.
     */
    private function fullMatrix(float $base = 100.0): array
    {
        $prices = [];

        foreach ($this->zoneIds() as $i => $zoneId) {
            foreach ($this->tariffIds() as $j => $tariffId) {
                $prices[$zoneId][$tariffId] = number_format($base + $i + $j, 2, '.', '');
            }
        }

        return $prices;
    }

    /**
     * The CSRF token of the price-save form, read the way a browser reads it.
     */
    private function priceToken(Performance $performance): string
    {
        $crawler = $this->client->request('GET', '/admin/events/'.$performance->getEvent()->getId().'/performances/'.$performance->getId().'/prices');

        $token = $crawler->filter('input[name="_token"]')->attr('value');

        self::assertIsString($token);

        return $token;
    }

    public function test_the_prices_screen_shows_every_zone_and_tariff(): void
    {
        $performance = $this->performance();

        $crawler = $this->client->request('GET', '/admin/events/'.$performance->getEvent()->getId().'/performances/'.$performance->getId().'/prices');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.page-header__title', 'Prices');

        // One input per zone × tariff combination.
        self::assertCount(count($this->zoneIds()) * count($this->tariffIds()), $crawler->filter('input[type="number"]'));

        // No price is set yet.
        self::assertSelectorTextContains('.alert--warning', 'cannot be published');
    }

    public function test_an_administrator_sets_the_prices(): void
    {
        $performance = $this->performance();

        $this->client->request('POST',
            '/admin/events/'.$performance->getEvent()->getId().'/performances/'.$performance->getId().'/prices',
            [
                '_token' => $this->priceToken($performance),
                'price' => $this->fullMatrix(),
            ],
        );

        self::assertResponseRedirects();

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Every combination is priced.');

        $em = $this->entityManager();
        $em->clear();

        $prices = $em->getRepository(PerformancePrice::class)
            ->findBy(['performance' => $performance, 'isCurrent' => true]);

        self::assertCount(count($this->zoneIds()) * count($this->tariffIds()), $prices);
    }

    public function test_changing_a_price_creates_a_new_version_and_retires_the_old_one(): void
    {
        $performance = $this->performance();
        $em = $this->entityManager();

        // First version.
        $this->client->request('POST',
            '/admin/events/'.$performance->getEvent()->getId().'/performances/'.$performance->getId().'/prices',
            [
                '_token' => $this->priceToken($performance),
                'price' => $this->fullMatrix(100),
            ],
        );

        // Change one cell.
        $matrix = $this->fullMatrix(100);
        $zoneIds = $this->zoneIds();
        $tariffIds = $this->tariffIds();
        $matrix[$zoneIds[0]][$tariffIds[0]] = '200.00';

        $this->client->request('POST',
            '/admin/events/'.$performance->getEvent()->getId().'/performances/'.$performance->getId().'/prices',
            [
                '_token' => $this->priceToken($performance),
                'price' => $matrix,
            ],
        );

        $em->clear();

        $current = $em->getRepository(PerformancePrice::class)->findBy([
            'performance' => $performance,
            'isCurrent' => true,
        ]);

        self::assertCount(count($zoneIds) * count($tariffIds), $current);

        $pricesForCombo = $em->getRepository(PerformancePrice::class)->findBy([
            'performance' => $performance,
            'layoutZone' => $em->getReference(LayoutZone::class, $zoneIds[0]),
            'tariff' => $em->getReference(Tariff::class, $tariffIds[0]),
        ], ['version' => 'ASC']);

        self::assertCount(2, $pricesForCombo);
        self::assertSame(1, $pricesForCombo[0]->getVersion());
        self::assertFalse($pricesForCombo[0]->isCurrent());
        self::assertSame(2, $pricesForCombo[1]->getVersion());
        self::assertTrue($pricesForCombo[1]->isCurrent());
        self::assertSame('200.00', $pricesForCombo[1]->getAmount());
    }

    public function test_clearing_a_cell_removes_that_price(): void
    {
        $performance = $this->performance();
        $em = $this->entityManager();

        // Start with a full matrix.
        $this->client->request('POST',
            '/admin/events/'.$performance->getEvent()->getId().'/performances/'.$performance->getId().'/prices',
            [
                '_token' => $this->priceToken($performance),
                'price' => $this->fullMatrix(),
            ],
        );

        // Clear one cell.
        $matrix = $this->fullMatrix();
        $zoneIds = $this->zoneIds();
        $tariffIds = $this->tariffIds();
        $matrix[$zoneIds[0]][$tariffIds[0]] = '';

        $this->client->request('POST',
            '/admin/events/'.$performance->getEvent()->getId().'/performances/'.$performance->getId().'/prices',
            [
                '_token' => $this->priceToken($performance),
                'price' => $matrix,
            ],
        );

        $em->clear();

        $current = $em->getRepository(PerformancePrice::class)->findBy([
            'performance' => $performance,
            'isCurrent' => true,
        ]);

        self::assertCount(count($zoneIds) * count($tariffIds) - 1, $current);
    }

    public function test_an_unreadable_price_is_rejected(): void
    {
        $performance = $this->performance();
        $matrix = $this->fullMatrix();
        $zoneIds = $this->zoneIds();
        $tariffIds = $this->tariffIds();
        $matrix[$zoneIds[0]][$tariffIds[0]] = 'abc';

        $this->client->request('POST',
            '/admin/events/'.$performance->getEvent()->getId().'/performances/'.$performance->getId().'/prices',
            [
                '_token' => $this->priceToken($performance),
                'price' => $matrix,
            ],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'not a valid price');
    }

    public function test_an_event_can_be_published_once_its_prices_are_set(): void
    {
        $performance = $this->performance();
        $event = $performance->getEvent();

        // The event is complete once its performance has prices.
        $this->client->request('POST',
            '/admin/events/'.$event->getId().'/performances/'.$performance->getId().'/prices',
            [
                '_token' => $this->priceToken($performance),
                'price' => $this->fullMatrix(),
            ],
        );

        $crawler = $this->client->request('GET', '/admin/events/'.$event->getId());

        self::assertSelectorTextContains('.checklist', 'The prices are set');

        $form = $crawler->filter('form[action*="/publish"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/events/'.$event->getId());

        $em = $this->entityManager();
        $em->clear();
        self::assertSame('PUBLISHED', $em->getRepository(Event::class)->find($event->getId())->getStatus()->value);
    }

    public function test_deleting_a_performance_with_prices_works(): void
    {
        $performance = $this->performance();
        $em = $this->entityManager();

        // Give the performance prices.
        $this->client->request('POST',
            '/admin/events/'.$performance->getEvent()->getId().'/performances/'.$performance->getId().'/prices',
            [
                '_token' => $this->priceToken($performance),
                'price' => $this->fullMatrix(),
            ],
        );

        self::assertCount(count($this->zoneIds()) * count($this->tariffIds()),
            $em->getRepository(PerformancePrice::class)->findBy(['performance' => $performance]));

        // Delete the performance.
        $crawler = $this->client->request('GET', '/admin/events/'.$performance->getEvent()->getId());
        $form = $crawler->filter('form[action*="/performances/'.$performance->getId().'/delete"]')->form();
        $this->client->submit($form);

        $em->clear();

        self::assertNull($em->getRepository(Performance::class)->find($performance->getId()));
        self::assertCount(0, $em->getRepository(PerformancePrice::class)->findBy(['performance' => $performance]));
    }
}
