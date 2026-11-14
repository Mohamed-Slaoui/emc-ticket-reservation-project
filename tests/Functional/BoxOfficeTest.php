<?php

namespace App\Tests\Functional;

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
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\ReservationStatus;
use App\Enum\TariffCode;

use App\Repository\TariffRepository;
use App\Service\Event\EventService;
use App\Service\Event\PerformanceService;
use App\Tests\Functional\FunctionalTestCase;

/**
 * The box-office counter — search a show, sell seats, record the payment,
 * hand over the tickets (docs/WORKFLOWS.md WF-2).
 */
class BoxOfficeTest extends FunctionalTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_BOX_OFFICE_EMAIL]));
    }

    private function publishPerformance(): Performance
    {
        $em = $this->entityManager();
        $event = new Event('Soirée de gala', EventType::Theatre);
        $em->persist($event);
        $em->flush();

        $layout = $em->getRepository(SeatingLayout::class)->findOneBy(['version' => 1]);
        self::assertInstanceOf(SeatingLayout::class, $layout);

        $starts = new \DateTimeImmutable('+30 days');
        $performance = static::getContainer()->get(PerformanceService::class)
            ->create($event, $layout, $starts, $starts->modify('+2 hours'));

        $tariffs = $em->getRepository(Tariff::class)->findAll();
        $zones = $em->getRepository(\App\Entity\LayoutZone::class)->findForLayout($layout);

        foreach ($tariffs as $j => $tariff) {
            foreach ($zones as $i => $zone) {
                $em->persist(new PerformancePrice($performance, $zone, $tariff, number_format(150 - $i * 30 - $j * 5, 2, '.', ''), 'MAD', 1));
            }
        }

        $em->flush();
        static::getContainer()->get(EventService::class)->publish($performance->getEvent());

        return $performance;
    }

    private function seatIds(Performance $performance, int $count): array
    {
        $places = $this->entityManager()->getRepository(PerformancePlace::class)->findForPerformance($performance);

        return array_map(static fn (PerformancePlace $place): int => (int) $place->getId(), array_slice($places, 0, $count));
    }

    public function test_the_desk_lists_the_on_sale_shows(): void
    {
        $performance = $this->publishPerformance();

        $crawler = $this->client->request('GET', '/box-office');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.table', 'Soirée de gala');
        self::assertStringContainsString('/box-office/performances/'.$performance->getId(), $crawler->html());

        // Search narrows by event title.
        $crawler = $this->client->request('GET', '/box-office?q=gala');
        self::assertStringContainsString('Soirée de gala', $crawler->html());

        $crawler = $this->client->request('GET', '/box-office?q=nothing-matches');
        self::assertStringNotContainsString('Soirée de gala', $crawler->html());
    }

    public function test_the_sell_page_embeds_every_tariff_price(): void
    {
        $performance = $this->publishPerformance();

        $crawler = $this->client->request('GET', '/box-office/performances/'.$performance->getId());

        self::assertResponseIsSuccessful();

        // The JSON island carries every tariff the seller may choose.
        foreach (['FULL', 'REDUCED', 'GROUP', 'INVITATION'] as $code) {
            self::assertStringContainsString('"'.$code.'"', $crawler->html());
        }

        // Seats carry their zone so the tariff picker can reprice them.
        self::assertSelectorExists('input.seat__input[data-zone]');
        self::assertSelectorExists('span[data-zone-price]');
        self::assertSelectorExists('input[name="tariff"]');
    }

    public function test_a_full_tariff_sale_confirms_and_issues_tickets(): void
    {
        $performance = $this->publishPerformance();
        $ids = $this->seatIds($performance, 2);

        $this->client->request('GET', '/box-office/performances/'.$performance->getId());
        $token = $this->client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/box-office/performances/'.$performance->getId().'/sell', [
            '_token' => $token,
            'places' => $ids,
            'tariff' => TariffCode::Full->value,
            'payment_method' => PaymentMethod::Cash->value,
        ]);

        self::assertResponseRedirects();

        $reservation = $this->entityManager()->getRepository(Reservation::class)->findOneBy([]);
        self::assertInstanceOf(Reservation::class, $reservation);
        self::assertSame(ReservationStatus::Confirmed, $reservation->getStatus());
        self::assertSame('300.00', $reservation->getTotalAmount());

        $payments = $this->entityManager()->getRepository(Payment::class)->findBy(['reservation' => $reservation]);
        self::assertCount(1, $payments);
        self::assertSame(PaymentStatus::Succeeded, $payments[0]->getStatus());
        self::assertSame(PaymentMethod::Cash, $payments[0]->getMethod());

        $tickets = $this->entityManager()->getRepository(Ticket::class)->findAll();
        self::assertCount(2, $tickets);

        $places = $this->entityManager()->getRepository(PerformancePlace::class)->findForPerformance($performance);

        foreach ($places as $place) {
            if (\in_array((int) $place->getId(), $ids, true)) {
                self::assertSame(InventoryStatus::Sold, $place->getStatus());
            }
        }
    }

    public function test_the_reduced_tariff_requires_the_document_verified(): void
    {
        $performance = $this->publishPerformance();
        $ids = $this->seatIds($performance, 1);

        $this->client->request('GET', '/box-office/performances/'.$performance->getId());
        $token = $this->client->getCrawler()->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/box-office/performances/'.$performance->getId().'/sell', [
            '_token' => $token,
            'places' => $ids,
            'tariff' => TariffCode::Reduced->value,
            'payment_method' => PaymentMethod::Cash->value,
        ]);

        self::assertResponseRedirects();
        self::assertSame(0, \count($this->entityManager()->getRepository(Reservation::class)->findBy([])));
    }

    public function test_the_limited_box_office_sees_only_full_and_group_tariffs(): void
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_BOX_OFFICE_LIMITED_EMAIL]));

        $performance = $this->publishPerformance();

        $crawler = $this->client->request('GET', '/box-office/performances/'.$performance->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="tariff"][value="FULL"]');
        self::assertSelectorExists('input[name="tariff"][value="GROUP"]');
        self::assertSelectorNotExists('input[name="tariff"][value="REDUCED"]');
        self::assertSelectorNotExists('input[name="tariff"][value="INVITATION"]');
    }

    public function test_the_limited_box_office_cannot_sell_reduced_even_with_the_document(): void
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_BOX_OFFICE_LIMITED_EMAIL]));

        $performance = $this->publishPerformance();
        $ids = $this->seatIds($performance, 1);

        $this->client->request('GET', '/box-office/performances/'.$performance->getId());
        // The document checkbox exists only when REDUCED is among the offered
        // tariffs — the limited seller does not see it, and the engine
        // refuses the reduced tariff for them either way.
        $token = $this->client->getCrawler()->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/box-office/performances/'.$performance->getId().'/sell', [
            '_token' => $token,
            'places' => $ids,
            'tariff' => TariffCode::Reduced->value,
            'eligibility_verified' => '1',
            'payment_method' => PaymentMethod::Cash->value,
        ]);

        self::assertResponseRedirects();
        self::assertSame(0, \count($this->entityManager()->getRepository(Reservation::class)->findBy([])));
    }

    public function test_the_limited_box_office_cannot_sell_invitation_tariff(): void
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_BOX_OFFICE_LIMITED_EMAIL]));

        $performance = $this->publishPerformance();
        $ids = $this->seatIds($performance, 1);

        $this->client->request('GET', '/box-office/performances/'.$performance->getId());
        $token = $this->client->getCrawler()->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/box-office/performances/'.$performance->getId().'/sell', [
            '_token' => $token,
            'places' => $ids,
            'tariff' => TariffCode::Invitation->value,
            'payment_method' => PaymentMethod::Cash->value,
        ]);

        self::assertResponseRedirects();
        self::assertSame(0, \count($this->entityManager()->getRepository(Reservation::class)->findBy([])));
    }

    public function test_an_administrator_can_sell_invitation_tariff_with_no_permissions(): void
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]));

        $performance = $this->publishPerformance();
        $ids = $this->seatIds($performance, 1);

        $this->client->request('GET', '/box-office/performances/'.$performance->getId());
        $token = $this->client->getCrawler()->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/box-office/performances/'.$performance->getId().'/sell', [
            '_token' => $token,
            'places' => $ids,
            'tariff' => TariffCode::Invitation->value,
            'payment_method' => PaymentMethod::Cash->value,
        ]);

        self::assertResponseRedirects();
        self::assertSame(1, \count($this->entityManager()->getRepository(Reservation::class)->findBy([])));
    }

    public function test_the_group_tariff_needs_at_least_five_seats(): void
    {
        $performance = $this->publishPerformance();
        $ids = $this->seatIds($performance, 4);

        $this->client->request('GET', '/box-office/performances/'.$performance->getId());
        $token = $this->client->getCrawler()->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/box-office/performances/'.$performance->getId().'/sell', [
            '_token' => $token,
            'places' => $ids,
            'tariff' => TariffCode::Group->value,
            'payment_method' => PaymentMethod::Cash->value,
        ]);

        self::assertResponseRedirects();
        self::assertSame(0, \count($this->entityManager()->getRepository(Reservation::class)->findBy([])));
    }

    public function test_an_administrative_account_can_sell_at_the_counter(): void
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]));

        $performance = $this->publishPerformance();
        $ids = $this->seatIds($performance, 1);

        $this->client->request('GET', '/box-office/performances/'.$performance->getId());
        $token = $this->client->getCrawler()->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/box-office/performances/'.$performance->getId().'/sell', [
            '_token' => $token,
            'places' => $ids,
            'tariff' => TariffCode::Full->value,
            'payment_method' => PaymentMethod::CardTerminal->value,
        ]);

        self::assertResponseRedirects();
        self::assertSame(1, \count($this->entityManager()->getRepository(Reservation::class)->findBy([])));
    }

    public function test_a_customer_account_cannot_sell(): void
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]));

        $this->client->request('GET', '/box-office');

        self::assertResponseStatusCodeSame(403);
    }
}
