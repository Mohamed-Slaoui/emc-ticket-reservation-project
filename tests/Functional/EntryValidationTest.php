<?php

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\Event;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Entity\PerformancePrice;
use App\Entity\Reservation;
use App\Entity\SeatingLayout;
use App\Entity\Tariff;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\EventType;
use App\Enum\SalesChannel;
use App\Enum\TariffCode;
use App\Enum\TicketStatus;
use App\Repository\TariffRepository;
use App\Service\Event\EventService;
use App\Service\Event\PerformanceService;

/**
 * The entry — the scanner at the door (docs/WORKFLOWS.md §5).
 *
 * A ticket is used **once**: the second scan is refused (BR-13), and a
 * void ticket is refused too (INV-07).
 */
class EntryValidationTest extends FunctionalTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_BOX_OFFICE_EMAIL]));
    }

    private function sellTicket(): Ticket
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
        static::getContainer()->get(EventService::class)->publish($event);

        // Hold a seat as the customer, then pay for it.
        $customer = $em->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]);
        self::assertInstanceOf(User::class, $customer);

        $places = $em->getRepository(PerformancePlace::class)->findForPerformance($performance);
        $place = array_values($places)[0];

        $reservation = static::getContainer()->get(\App\Service\Reservation\ReservationService::class)
            ->hold($performance, [(int) $place->getId()], TariffCode::Full, SalesChannel::Online, $customer, $customer);

        static::getContainer()->get(\App\Service\Payment\PaymentService::class)->pay($reservation, ['cardNumber' => '4242 4242 4242 4242']);

        $em->clear();

        $ticket = $em->getRepository(Ticket::class)->findOneBy(['reservationItem' => $reservation->getItems()->first()]);
        self::assertInstanceOf(Ticket::class, $ticket);

        return $ticket;
    }

    public function test_a_valid_ticket_admits_its_holder(): void
    {
        $ticket = $this->sellTicket();

        $this->client->request('POST', '/box-office/entry', ['code' => $ticket->getTicketNumber()]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert--success', 'Admitted');

        $em = $this->entityManager();
        $em->clear();

        $ticket = $em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertSame(TicketStatus::Used, $ticket->getStatus());
        self::assertNotNull($ticket->getUsedAt());
    }

    public function test_the_claim_code_is_read_too(): void
    {
        $ticket = $this->sellTicket();

        $this->client->request('POST', '/box-office/entry', ['code' => $ticket->getQrToken()]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert--success', 'Admitted');
    }

    public function test_a_ticket_cannot_be_used_twice(): void
    {
        $ticket = $this->sellTicket();

        $this->client->request('POST', '/box-office/entry', ['code' => $ticket->getTicketNumber()]);
        self::assertResponseIsSuccessful();

        // The second scan is refused (BR-13).
        $this->client->request('POST', '/box-office/entry', ['code' => $ticket->getTicketNumber()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'already used');
    }

    public function test_a_void_ticket_is_refused(): void
    {
        $ticket = $this->sellTicket();
        $ticket->void();
        $this->entityManager()->flush();

        $this->client->request('POST', '/box-office/entry', ['code' => $ticket->getTicketNumber()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'Void ticket rejected');
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $this->client->request('POST', '/box-office/entry', ['code' => 'TKT-000000-0000']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'No ticket matches');
    }

    public function test_a_customer_cannot_open_the_scanner(): void
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]));

        $this->client->request('GET', '/box-office/entry');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_tickets_are_printable(): void
    {
        $ticket = $this->sellTicket();

        $customer = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]);
        $this->client->loginUser($customer);

        $crawler = $this->client->request('GET', '/reservations/'.$ticket->getReservationItem()->getReservation()->getId().'/tickets');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ticket__event', 'Soirée de gala');
        self::assertSelectorTextContains('.ticket__ref', $ticket->getTicketNumber());
        self::assertSelectorTextContains('.ticket__claim', $ticket->getQrToken());
    }
}
