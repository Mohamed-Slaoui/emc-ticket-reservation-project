<?php

namespace App\Tests\Integration;

use App\Entity\Event;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Entity\PerformancePrice;
use App\Entity\Reservation;
use App\Entity\SeatingLayout;
use App\Entity\Tariff;
use App\Entity\User;
use App\Enum\EventType;
use App\Enum\InventoryStatus;
use App\Enum\ReservationItemStatus;
use App\Enum\ReservationStatus;
use App\Enum\SalesChannel;
use App\Enum\TariffCode;
use App\Exception\ReservationEngineException;
use App\Exception\SeatNotAvailableException;
use App\Exception\TariffNotAuthorizedException;
use App\Repository\PerformancePlaceRepository;
use App\Repository\TariffRepository;
use App\Service\Event\PerformanceService;
use App\Service\Reservation\ReservationService;
use App\DataFixtures\AppFixtures;

/**
 * The reservation engine (docs/WORKFLOWS.md WF-1) against a real database:
 * holds, availability, the group rule, expiry, confirmation, cancellation.
 */
class ReservationEngineTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->bootWithFixtures();
    }

    private function performance(): Performance
    {
        $em = $this->entityManager();
        $event = new Event('Soirée de gala', EventType::Theatre);
        $em->persist($event);
        $em->flush();

        $layout = $em->getRepository(SeatingLayout::class)->findOneBy(['version' => 1]);
        self::assertInstanceOf(SeatingLayout::class, $layout);

        $service = static::getContainer()->get(PerformanceService::class);
        $offset = 30 + self::$counter * 10;
        self::$counter++;

        $startsAt = new \DateTimeImmutable(sprintf('+%d days', $offset));

        return $service->create($event, $layout, $startsAt, $startsAt->modify('+2 hours'));
    }

    private static int $counter = 0;

    private function admin(): User
    {
        return $this->entityManager()->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]);
    }

    private function placeIds(Performance $performance, int $count): array
    {
        $places = $this->entityManager()->getRepository(PerformancePlace::class)->findForPerformance($performance);

        return array_map(static fn (PerformancePlace $place): int => (int) $place->getId(), array_slice($places, 0, $count));
    }

    private function savePrice(Performance $performance, TariffCode $tariffCode, string $amount): void
    {
        $em = $this->entityManager();
        $tariff = $em->getRepository(Tariff::class)->findOneBy(['code' => $tariffCode->value]);
        self::assertInstanceOf(Tariff::class, $tariff);

        $zones = $em->getRepository(\App\Entity\LayoutZone::class)->findForLayout($performance->getSeatingLayout());

        foreach ($zones as $i => $zone) {
            $em->persist(new PerformancePrice($performance, $zone, $tariff, $amount, 'MAD', $i + 1));
        }

        $em->flush();
    }

    public function test_hold_creates_the_reservation_with_snapshots(): void
    {
        $performance = $this->performance();
        $this->savePrice($performance, TariffCode::Full, '150.00');

        $ids = $this->placeIds($performance, 2);

        $service = static::getContainer()->get(ReservationService::class);
        $reservation = $service->hold($performance, $ids, TariffCode::Full, SalesChannel::Online, $this->admin());

        self::assertSame(ReservationStatus::Held, $reservation->getStatus());
        self::assertSame('300.00', $reservation->getTotalAmount());
        self::assertCount(2, $reservation->getItems());

        foreach ($reservation->getItems() as $item) {
            self::assertSame(ReservationItemStatus::Held, $item->getStatus());
            self::assertSame('Full tariff', $item->getTariffNameSnapshot());
            self::assertSame('150.00', $item->getUnitPriceSnapshot());
        }

        $places = $this->entityManager()->getRepository(PerformancePlace::class)->findForPerformance($performance);

        foreach ($places as $place) {
            if (\in_array((int) $place->getId(), $ids, true)) {
                self::assertSame(InventoryStatus::Held, $place->getStatus());
                self::assertSame($reservation->getId(), $place->getCurrentReservation()?->getId());
            }
        }
    }

    public function test_a_seat_already_held_cannot_be_held_again(): void
    {
        $performance = $this->performance();
        $this->savePrice($performance, TariffCode::Full, '150.00');

        $ids = $this->placeIds($performance, 1);

        $service = static::getContainer()->get(ReservationService::class);
        $service->hold($performance, $ids, TariffCode::Full, SalesChannel::Online, $this->admin());

        $this->entityManager()->clear();
        $this->expectException(SeatNotAvailableException::class);
        $service->hold($performance, $ids, TariffCode::Full, SalesChannel::Online, $this->admin());
    }

    public function test_seats_of_another_performance_cannot_be_held(): void
    {
        $first = $this->performance();
        $second = $this->performance();
        $this->savePrice($first, TariffCode::Full, '150.00');
        $this->savePrice($second, TariffCode::Full, '150.00');

        $ids = $this->placeIds($first, 1);

        $service = static::getContainer()->get(ReservationService::class);

        $this->expectException(SeatNotAvailableException::class);
        $service->hold($second, $ids, TariffCode::Full, SalesChannel::Online, $this->admin());
    }

    public function test_the_online_channel_sells_the_full_tariff_only(): void
    {
        $performance = $this->performance();
        $this->savePrice($performance, TariffCode::Reduced, '90.00');

        $service = static::getContainer()->get(ReservationService::class);
        $ids = $this->placeIds($performance, 1);

        $this->expectException(TariffNotAuthorizedException::class);
        $service->hold($performance, $ids, TariffCode::Reduced, SalesChannel::Online, $this->admin());
    }

    public function test_the_group_rule_requires_at_least_five_seats(): void
    {
        $performance = $this->performance();
        $this->savePrice($performance, TariffCode::Group, '100.00');

        $service = static::getContainer()->get(ReservationService::class);

        $this->expectException(TariffNotAuthorizedException::class);
        $service->hold($performance, $this->placeIds($performance, 4), TariffCode::Group, SalesChannel::BoxOffice, $this->admin());

        $reservation = $service->hold($performance, $this->placeIds($performance, 5), TariffCode::Group, SalesChannel::BoxOffice, $this->admin());

        self::assertCount(5, $reservation->getItems());
    }

    public function test_hold_refuses_when_the_zone_has_no_price(): void
    {
        $performance = $this->performance(); // no price saved

        $service = static::getContainer()->get(ReservationService::class);
        $ids = $this->placeIds($performance, 1);

        $this->expectException(ReservationEngineException::class);
        $service->hold($performance, $ids, TariffCode::Full, SalesChannel::Online, $this->admin());
    }

    public function test_expire_due_holds_releases_the_seats_and_keeps_the_history(): void
    {
        $performance = $this->performance();
        $this->savePrice($performance, TariffCode::Full, '150.00');

        $service = static::getContainer()->get(ReservationService::class);
        $ids = $this->placeIds($performance, 2);
        $reservation = $service->hold($performance, $ids, TariffCode::Full, SalesChannel::Online, $this->admin());

        // Force the hold into the past — the entity is HELD, only time is missing.
        $em = $this->entityManager();
        $em->getConnection()->executeStatement(
            'UPDATE reservation SET expires_at = DATE_SUB(NOW(6), INTERVAL 1 MINUTE) WHERE id = ?',
            [$reservation->getId()],
        );
        $em->clear();

        self::assertSame(1, $service->expireDueHolds());

        $em->clear();
        $reservation = $em->getRepository(Reservation::class)->find($reservation->getId());

        self::assertSame(ReservationStatus::Expired, $reservation->getStatus());

        foreach ($reservation->getItems() as $item) {
            self::assertSame(ReservationItemStatus::Expired, $item->getStatus());
        }

        // The seats are back on sale and the items are still in the database.
        $places = $em->getRepository(PerformancePlace::class)->findForPerformance($reservation->getPerformance());

        foreach ($places as $place) {
            if (\in_array((int) $place->getId(), $ids, true)) {
                self::assertSame(InventoryStatus::Available, $place->getStatus());
                self::assertNull($place->getCurrentReservation());
            }
        }
    }

    public function test_confirm_marks_the_reservation_and_sells_the_seats(): void
    {
        $performance = $this->performance();
        $this->savePrice($performance, TariffCode::Full, '150.00');

        $service = static::getContainer()->get(ReservationService::class);
        $reservation = $service->hold($performance, $this->placeIds($performance, 2), TariffCode::Full, SalesChannel::Online, $this->admin());

        $service->confirm($reservation);
        $this->entityManager()->clear();

        $em = $this->entityManager();
        $reservation = $em->getRepository(Reservation::class)->find($reservation->getId());

        self::assertSame(ReservationStatus::Confirmed, $reservation->getStatus());
        self::assertNotNull($reservation->getConfirmedAt());

        foreach ($reservation->getItems() as $item) {
            self::assertSame(ReservationItemStatus::Confirmed, $item->getStatus());
        }

        $places = $em->getRepository(PerformancePlace::class)->findForPerformance($reservation->getPerformance());
        $sold = array_filter($places, static fn (PerformancePlace $place): bool => InventoryStatus::Sold === $place->getStatus());
        self::assertCount(2, $sold);
    }

    public function test_confirm_after_expiry_is_refused(): void
    {
        $performance = $this->performance();
        $this->savePrice($performance, TariffCode::Full, '150.00');

        $service = static::getContainer()->get(ReservationService::class);
        $reservation = $service->hold($performance, $this->placeIds($performance, 1), TariffCode::Full, SalesChannel::Online, $this->admin());

        $em = $this->entityManager();
        $em->getConnection()->executeStatement(
            'UPDATE reservation SET expires_at = DATE_SUB(NOW(6), INTERVAL 1 MINUTE) WHERE id = ?',
            [$reservation->getId()],
        );
        $em->clear();

        // The entity above holds its original state — read it back fresh.
        $reservation = $em->getRepository(Reservation::class)->find($reservation->getId());

        $this->expectException(\LogicException::class);
        $service->confirm($reservation);
    }

    public function test_cancel_own_hold_releases_the_seats(): void
    {
        $performance = $this->performance();
        $this->savePrice($performance, TariffCode::Full, '150.00');

        $service = static::getContainer()->get(ReservationService::class);
        $ids = $this->placeIds($performance, 2);
        $reservation = $service->hold($performance, $ids, TariffCode::Full, SalesChannel::Online, $this->admin());

        $service->cancelHeldReservation($reservation, $this->admin());
        $this->entityManager()->clear();

        $em = $this->entityManager();
        $reservation = $em->getRepository(Reservation::class)->find($reservation->getId());

        self::assertSame(ReservationStatus::Cancelled, $reservation->getStatus());
        self::assertNotNull($reservation->getCancelledAt());

        $places = $em->getRepository(PerformancePlace::class)->findForPerformance($reservation->getPerformance());

        foreach ($places as $place) {
            if (\in_array((int) $place->getId(), $ids, true)) {
                self::assertSame(InventoryStatus::Available, $place->getStatus());
            }
        }
    }

    public function test_cancel_hold_of_another_account_is_refused(): void
    {
        $performance = $this->performance();
        $this->savePrice($performance, TariffCode::Full, '150.00');

        $service = static::getContainer()->get(ReservationService::class);
        $reservation = $service->hold($performance, $this->placeIds($performance, 1), TariffCode::Full, SalesChannel::Online, $this->admin());

        $other = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_BOX_OFFICE_EMAIL]);
        self::assertInstanceOf(User::class, $other);

        $this->expectException(ReservationEngineException::class);
        $service->cancelHeldReservation($reservation, $other);
    }
}
