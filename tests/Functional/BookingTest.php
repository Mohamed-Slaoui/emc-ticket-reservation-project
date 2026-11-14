<?php

namespace App\Tests\Functional\Booking;

use App\DataFixtures\AppFixtures;
use App\Entity\Event;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Entity\PerformancePrice;
use App\Entity\Payment;
use App\Entity\Reservation;
use App\Entity\SeatingLayout;
use App\Entity\Tariff;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\EventType;
use App\Enum\InventoryStatus;
use App\Enum\PaymentStatus;
use App\Enum\ReservationStatus;
use App\Enum\TariffCode;
use App\Repository\PerformancePlaceRepository;
use App\Repository\TariffRepository;
use App\Service\Event\EventService;
use App\Service\Event\PerformanceService;
use App\Tests\Functional\FunctionalTestCase;

/**
 * The public shop — the programme, the seat map, the hold and the
 * "My reservations" area (docs/WORKFLOWS.md WF-1, interface).
 */
class BookingTest extends FunctionalTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]));
    }

    private function performance(): Performance
    {
        $em = $this->entityManager();
        $event = new Event('Soirée de gala', EventType::Theatre);
        $em->persist($event);
        $em->flush();

        $layout = $em->getRepository(SeatingLayout::class)->findOneBy(['version' => 1]);
        self::assertInstanceOf(SeatingLayout::class, $layout);

        $performance = static::getContainer()->get(PerformanceService::class)
            ->create($event, $layout, new \DateTimeImmutable('+30 days'), (new \DateTimeImmutable('+30 days'))->modify('+2 hours'));

        // Prices for every tariff on every zone — the publication needs them.
        $tariffs = $em->getRepository(Tariff::class)->findAll();

        $zones = $em->getRepository(\App\Entity\LayoutZone::class)->findForLayout($layout);

        foreach ($tariffs as $j => $tariff) {
            foreach ($zones as $i => $zone) {
                $em->persist(new PerformancePrice($performance, $zone, $tariff, number_format(150 - $i * 30 - $j * 5, 2, '.', ''), 'MAD', 1));
            }
        }

        $em->flush();

        $event = $em->getRepository(Event::class)->find($event->getId());
        static::getContainer()->get(EventService::class)->publish($event);

        return $performance;
    }

    private function showUrl(Performance $performance): string
    {
        return '/events/'.$performance->getEvent()->getId().'/performances/'.$performance->getId();
    }

    private function holdSeats(Performance $performance, array $ids): void
    {
        $this->client->request('GET', $this->showUrl($performance));
        $token = $this->client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/reservations/hold/'.$performance->getId(), [
            '_token' => $token,
            'places' => $ids,
        ]);
    }

    private function reservation(): Reservation
    {
        $reservation = $this->entityManager()->getRepository(Reservation::class)->findOneBy([]);
        self::assertInstanceOf(Reservation::class, $reservation);

        return $reservation;
    }

    public function test_the_programme_is_public(): void
    {
        $performance = $this->performance();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.table', 'Soirée de gala');
        self::assertStringContainsString('/events/'.$performance->getEvent()->getId().'/performances/'.$performance->getId(), $crawler->html());
    }

    public function test_the_event_page_lists_its_performances(): void
    {
        $performance = $this->performance();

        $crawler = $this->client->request('GET', '/events/'.$performance->getEvent()->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Soirée de gala', $crawler->html());
        self::assertStringContainsString($this->showUrl($performance), $crawler->html());
    }

    public function test_an_anonymous_visitor_can_browse_but_not_hold(): void
    {
        $performance = $this->performance();

        $this->client->request('GET', '/logout');
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Soirée de gala', $crawler->html());

        // The seat map page renders for a guest; an alert explains that an
        // account is required and the hold button stays disabled.
        $crawler = $this->client->request('GET', $this->showUrl($performance));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert--info', 'An account is needed to book a seat');
        self::assertStringContainsString('/register', $crawler->html());

        $button = $crawler->filter('[data-booking-target="submit"]')->first();
        self::assertNotNull($button->attr('disabled'));
        self::assertSame('true', $crawler->filter('[data-booking-locked]')->first()->attr('data-booking-locked'));

        // But the hold itself requires an account.
        $this->client->request('POST', '/reservations/hold/'.$performance->getId(), ['_token' => 'x', 'places' => [1]]);
        self::assertResponseRedirects('/login');
    }

    public function test_the_performance_page_renders_the_seat_map_publicly(): void
    {
        $performance = $this->performance();

        $this->client->request('GET', '/logout');
        $crawler = $this->client->request('GET', $this->showUrl($performance));

        self::assertResponseIsSuccessful();
        self::assertCount(400, $crawler->filter('.seat__input'));
    }

    public function test_holding_a_seat_creates_the_reservation(): void
    {
        $performance = $this->performance();
        $places = $this->entityManager()->getRepository(PerformancePlace::class)->findForPerformance($performance);
        $ids = array_map(static fn (PerformancePlace $place): int => (int) $place->getId(), \array_slice($places, 0, 2));

        $this->holdSeats($performance, $ids);
        self::assertResponseRedirects();

        $this->client->followRedirect();
        self::assertSelectorTextContains('.page-header__title', 'Reservation');

        $em = $this->entityManager();
        $em->clear();

        $reservation = $this->reservation();

        self::assertSame(ReservationStatus::Held, $reservation->getStatus());
        self::assertSame('300.00', $reservation->getTotalAmount());
        self::assertCount(2, $reservation->getItems());
        self::assertSame(AppFixtures::USER_CUSTOMER_EMAIL, $reservation->getCustomer()?->getEmail());

        $places = $em->getRepository(PerformancePlace::class)->findForPerformance($performance);

        foreach ($places as $place) {
            if (\in_array((int) $place->getId(), $ids, true)) {
                self::assertSame(InventoryStatus::Held, $place->getStatus());
            }
        }
    }

    public function test_an_administrator_can_buy_through_the_public_shop(): void
    {
        $performance = $this->performance();
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]));

        $places = $this->entityManager()->getRepository(PerformancePlace::class)->findForPerformance($performance);
        $ids = [(int) array_values($places)[0]->getId()];

        // The administrator keeps the shop permissions: full tariff by default.
        $this->holdSeats($performance, $ids);
        self::assertResponseRedirects();

        $reservation = $this->reservation();

        // The administrator admin role is authorised to apply the group tariff too.
        $this->client->request('GET', '/reservations/'.$reservation->getId());
        self::assertSelectorTextContains('.page-header__title', $reservation->getReference());

        $this->payReservation($reservation);
        self::assertSelectorTextContains('.alert', 'Payment received');
    }

    public function test_the_payment_confirms_the_reservation_and_issues_the_tickets(): void
    {
        $performance = $this->performance();
        $places = $this->entityManager()->getRepository(PerformancePlace::class)->findForPerformance($performance);
        $ids = array_map(static fn (PerformancePlace $place): int => (int) $place->getId(), \array_slice($places, 0, 2));

        $this->holdSeats($performance, $ids);
        $reservation = $this->reservation();

        $this->payReservation($reservation);
        self::assertSelectorTextContains('.alert', 'Payment received');

        $em = $this->entityManager();
        $em->clear();

        $reservation = $em->getRepository(Reservation::class)->find($reservation->getId());
        self::assertSame(ReservationStatus::Confirmed, $reservation->getStatus());

        $payments = $em->getRepository(Payment::class)->findBy(['reservation' => $reservation]);
        self::assertCount(1, $payments);
        self::assertSame(PaymentStatus::Succeeded, $payments[0]->getStatus());
        self::assertSame('300.00', $payments[0]->getAmount());

        $tickets = $em->getRepository(Ticket::class)->findForReservation($reservation);
        self::assertCount(2, $tickets);

        foreach ($reservation->getItems() as $item) {
            self::assertSame('CONFIRMED', $item->getStatus()->value);
        }
    }

    public function test_a_reservation_can_be_seen_and_cancelled_by_its_owner(): void
    {
        $performance = $this->performance();
        $places = $this->entityManager()->getRepository(PerformancePlace::class)->findForPerformance($performance);
        $ids = [(int) array_values($places)[0]->getId()];

        $this->holdSeats($performance, $ids);
        $reservation = $this->reservation();

        $crawler = $this->client->request('GET', '/reservations');
        self::assertSelectorTextContains('.table', $reservation->getReference());

        $crawler = $this->client->request('GET', '/reservations/'.$reservation->getId());
        self::assertSelectorTextContains('.page-header__title', $reservation->getReference());

        // And cancelled — the seat goes back on sale.
        $cancelToken = $this->client->getCrawler()->filter('form[action$="/cancel"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/reservations/'.$reservation->getId().'/cancel', ['_token' => $cancelToken]);
        $this->client->followRedirect();

        $em = $this->entityManager();
        $em->clear();

        self::assertSame(ReservationStatus::Cancelled, $em->getRepository(Reservation::class)->find($reservation->getId())->getStatus());

        $place = $em->getRepository(PerformancePlace::class)->findForPerformance($performance);
        $place = array_values(array_filter($place, static fn (PerformancePlace $p): bool => \in_array((int) $p->getId(), $ids, true)))[0];
        self::assertSame(InventoryStatus::Available, $place->getStatus());
    }

    public function test_another_customer_cannot_see_the_reservation(): void
    {
        $performance = $this->performance();
        $places = $this->entityManager()->getRepository(PerformancePlace::class)->findForPerformance($performance);
        $ids = [(int) array_values($places)[0]->getId()];

        $this->holdSeats($performance, $ids);
        $reservation = $this->reservation();

        $other = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_BOX_OFFICE_EMAIL]);
        self::assertInstanceOf(User::class, $other);
        $this->client->loginUser($other);

        $this->client->request('GET', '/reservations/'.$reservation->getId());
        self::assertResponseStatusCodeSame(403);
    }

    private function payReservation(Reservation $reservation): void
    {
        $this->client->request('GET', '/reservations/'.$reservation->getId());
        $token = $this->client->getCrawler()->filter('form[action$="/pay"] input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/reservations/'.$reservation->getId().'/pay', ['_token' => $token]);
        $this->client->followRedirect();
    }
}
