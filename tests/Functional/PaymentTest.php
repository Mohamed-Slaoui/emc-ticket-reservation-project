<?php

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\Payment;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Entity\PerformancePrice;
use App\Entity\Refund;
use App\Entity\Reservation;
use App\Entity\SeatingLayout;
use App\Entity\Tariff;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\EventType;
use App\Enum\InventoryStatus;
use App\Enum\PaymentStatus;
use App\Enum\RefundStatus;
use App\Enum\ReservationStatus;
use App\Repository\PerformancePlaceRepository;
use App\Repository\TariffRepository;
use App\Service\Event\EventService;
use App\Service\Event\PerformanceService;
use App\Service\Payment\SimulatedPaymentProvider;

/**
 * The three outcomes of an online payment (docs/WORKFLOWS.md WF-1 §2.3):
 * approved, refused, and approved after the hold expired.
 */
class PaymentTest extends FunctionalTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]));
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
        static::getContainer()->get(EventService::class)->publish($event);

        return $performance;
    }

    private function hold(Performance $performance, int $seats = 1): Reservation
    {
        $places = $this->entityManager()->getRepository(PerformancePlace::class)->findForPerformance($performance);
        $ids = array_map(static fn (PerformancePlace $place): int => (int) $place->getId(), \array_slice($places, 0, $seats));

        $this->client->request('GET', '/events/'.$performance->getEvent()->getId().'/performances/'.$performance->getId());
        $token = $this->client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/reservations/hold/'.$performance->getId(), [
            '_token' => $token,
            'places' => $ids,
        ]);

        $reservation = $this->entityManager()->getRepository(Reservation::class)->findOneBy([]);
        self::assertInstanceOf(Reservation::class, $reservation, 'The hold failed: '.$this->client->getResponse()->getContent().' | token='.$token.' | ids='.implode(',', $ids));

        return $reservation;
    }

    private function pay(Reservation $reservation, string $cardNumber = '4242 4242 4242 4242'): void
    {
        $this->client->request('GET', '/reservations/'.$reservation->getId());
        $token = $this->client->getCrawler()->filter('form[action$="/pay"] input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/reservations/'.$reservation->getId().'/pay', [
            '_token' => $token,
            'cardHolder' => 'Karim Ouazzani',
            'cardNumber' => $cardNumber,
            'cardExpiry' => '12/28',
            'cardCvc' => '123',
        ]);
    }

    public function test_an_approved_payment_confirms_the_reservation_and_issues_the_tickets(): void
    {
        $performance = $this->publishPerformance();
        $reservation = $this->hold($performance, 2);

        $this->pay($reservation);

        $em = $this->entityManager();
        $em->clear();

        $reservation = $em->getRepository(Reservation::class)->find($reservation->getId());
        self::assertSame(ReservationStatus::Confirmed, $reservation->getStatus());

        $payments = $em->getRepository(Payment::class)->findBy(['reservation' => $reservation]);
        self::assertCount(1, $payments);
        self::assertSame(PaymentStatus::Succeeded, $payments[0]->getStatus());
        self::assertSame('300.00', $payments[0]->getAmount());

        $tickets = $em->getRepository(Ticket::class)->findBy(['reservationItem' => $reservation->getItems()->first()]);
        self::assertCount(1, $tickets);

        // One outbox message per ticket (the Sheets synchronisation, Phase 14).
        self::assertCount(2, $em->getRepository(OutboxMessage::class)->findBy(['reservation' => $reservation]));
    }

    public function test_a_refused_payment_keeps_the_seats_held_until_the_expiry(): void
    {
        $performance = $this->publishPerformance();
        $reservation = $this->hold($performance, 1);

        $this->pay($reservation, '4000 0000 0000 0000');

        $em = $this->entityManager();
        $em->clear();

        $reservation = $em->getRepository(Reservation::class)->find($reservation->getId());

        // BR-05 — the attempt is recorded, the reservation stays held.
        $payments = $em->getRepository(Payment::class)->findBy(['reservation' => $reservation]);
        self::assertCount(1, $payments);
        self::assertSame(PaymentStatus::Failed, $payments[0]->getStatus());
        self::assertSame(ReservationStatus::Held, $reservation->getStatus());

        // The seats are not released before the expiry.
        $places = $em->getRepository(PerformancePlace::class)->findForPerformance($performance);
        self::assertSame(InventoryStatus::Held, $places[0]->getStatus());

        // The customer may try again with another card.
        $this->pay($reservation, '4242 4242 4242 4242');

        $em->clear();
        self::assertSame(ReservationStatus::Confirmed, $em->getRepository(Reservation::class)->find($reservation->getId())->getStatus());
    }

    public function test_a_late_payment_is_refunded_in_full_and_issues_no_ticket(): void
    {
        $performance = $this->publishPerformance();
        $reservation = $this->hold($performance, 1);

        // The hold expires while the customer is paying.
        $em = $this->entityManager();
        $em->getConnection()->executeStatement(
            'UPDATE reservation SET expires_at = DATE_SUB(NOW(6), INTERVAL 1 MINUTE) WHERE id = ?',
            [$reservation->getId()],
        );
        $em->clear();

        $reservation = $em->getRepository(Reservation::class)->find($reservation->getId());
        self::assertInstanceOf(Reservation::class, $reservation);
        self::assertTrue($reservation->isExpired(), 'The hold must look expired before the payment.');

        $this->pay($reservation);

        $em->clear();

        $reservation = $em->getRepository(Reservation::class)->find($reservation->getId());

        // BR-06 — the money is captured, refunded in full, and no ticket is issued.
        $payments = $em->getRepository(Payment::class)->findBy(['reservation' => $reservation]);
        self::assertCount(1, $payments);
        self::assertSame(PaymentStatus::Succeeded, $payments[0]->getStatus());

        $refunds = $em->getRepository(Refund::class)->findBy(['payment' => $payments[0]]);
        file_put_contents('C:/Users/Mohamed/AppData/Local/Temp/opencode/pay-debug.json', json_encode(['refunds' => $em->getConnection()->fetchAllAssociative('SELECT id, amount, status, payment_id FROM refund'), 'payments' => $em->getConnection()->fetchAllAssociative('SELECT id, amount, status, reservation_id FROM payment'), 'reservations' => $em->getConnection()->fetchAllAssociative('SELECT id, status, expires_at FROM reservation')], JSON_PRETTY_PRINT));
        self::assertCount(1, $refunds);
        self::assertSame(RefundStatus::Pending, $refunds[0]->getStatus());
        self::assertSame($payments[0]->getAmount(), $refunds[0]->getAmount());

        self::assertCount(0, $em->getRepository(Ticket::class)->findBy(['reservationItem' => $reservation->getItems()->first()]));
    }

    public function test_the_simulated_provider_refuses_the_test_cards(): void
    {
        $provider = static::getContainer()->get(SimulatedPaymentProvider::class);

        self::assertTrue($provider->authorize('100.00', 'MAD', ['cardNumber' => '4242 4242 4242 4242'])->approved);
        self::assertFalse($provider->authorize('100.00', 'MAD', ['cardNumber' => '4000 0000 0000 0000'])->approved);
        self::assertFalse($provider->authorize('100.00', 'MAD', ['cardNumber' => ''])->approved);
    }
}
