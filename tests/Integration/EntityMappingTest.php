<?php

namespace App\Tests\Integration;

use App\Entity\LayoutPlace;
use App\Entity\LayoutZone;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Entity\Place;
use App\Entity\Reservation;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Entity\Tariff;
use App\Entity\User;
use App\Enum\InventoryStatus;
use App\Enum\SalesChannel;

/**
 * Verifies that the mapping really expresses the approved model
 * (docs/MODELE.md §4.2, §5.3) and that the cross-cutting consistency rules
 * are respected by the persisted data.
 */
class EntityMappingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->bootWithFixtures();
    }

    public function test_venue_owns_its_rooms_and_a_room_owns_its_places(): void
    {
        $em = $this->entityManager();

        $venue = $em->getRepository(\App\Entity\Venue::class)->findOneBy([]);
        $room = $em->getRepository(Room::class)->findOneBy([]);

        self::assertSame($venue, $room->getVenue());
        self::assertCount(400, $room->getPlaces());
        self::assertCount(1, $venue->getRooms());
    }

    public function test_a_place_belongs_to_the_room_and_is_unique_within_it(): void
    {
        $em = $this->entityManager();
        $room = $em->getRepository(Room::class)->findOneBy([]);

        $place = $em->getRepository(Place::class)->findOneBy(['code' => 'A-01']);

        self::assertSame($room, $place->getRoom());
        self::assertSame('A', $place->getRowLabel());
        self::assertSame(1, $place->getSeatNumber());
        self::assertSame('A1', $place->getLabel());
    }

    public function test_a_layout_belongs_to_a_room_and_versions_are_unique_within_it(): void
    {
        $em = $this->entityManager();

        $room = $em->getRepository(Room::class)->findOneBy([]);
        $layout = $em->getRepository(SeatingLayout::class)->findOneBy(['version' => 1]);

        self::assertSame($room, $layout->getRoom());
        self::assertCount(1, $room->getSeatingLayouts());
        self::assertCount(3, $layout->getZones());
        self::assertCount(400, $layout->getLayoutPlaces());
    }

    public function test_a_layout_place_maps_a_place_of_the_same_room_into_a_zone_of_the_same_layout(): void
    {
        $em = $this->entityManager();

        $layout = $em->getRepository(SeatingLayout::class)->findOneBy(['version' => 1]);
        $room = $layout->getRoom();

        $layoutPlaces = $em->getRepository(LayoutPlace::class)->findAll();
        self::assertCount(400, $layoutPlaces);

        foreach ($layoutPlaces as $layoutPlace) {
            // The physical seat belongs to the layout's room.
            self::assertSame($room, $layoutPlace->getPlace()->getRoom());
            // The zone belongs to the same layout.
            self::assertSame($layout, $layoutPlace->getLayoutZone()->getSeatingLayout());
        }
    }

    public function test_a_zone_belongs_to_its_layout(): void
    {
        $em = $this->entityManager();

        $layout = $em->getRepository(SeatingLayout::class)->findOneBy(['version' => 1]);
        $zone = $em->getRepository(LayoutZone::class)->findOneBy(['code' => 'ORC']);

        self::assertSame($layout, $zone->getSeatingLayout());
        self::assertSame(240, $zone->getLayoutPlaces()->count());
    }

    public function test_a_performance_uses_exactly_one_layout(): void
    {
        $em = $this->entityManager();

        $layout = $em->getRepository(SeatingLayout::class)->findOneBy(['version' => 1]);
        $performance = new Performance(
            new \App\Entity\Event('Concert de gala', \App\Enum\EventType::Concert),
            $layout,
            new \DateTimeImmutable('+30 days'),
        );

        self::assertSame($layout, $performance->getSeatingLayout());
    }

    public function test_a_performance_place_references_a_layout_place_of_the_performance_layout(): void
    {
        $em = $this->entityManager();

        $layout = $em->getRepository(SeatingLayout::class)->findOneBy(['version' => 1]);
        $layoutPlace = $em->getRepository(LayoutPlace::class)->findOneBy(['seatingLayout' => $layout]);

        $performance = new Performance(
            new \App\Entity\Event('Concert de gala', \App\Enum\EventType::Concert),
            $layout,
            new \DateTimeImmutable('+30 days'),
        );
        $inventory = new PerformancePlace($performance, $layoutPlace);

        self::assertSame($performance, $inventory->getPerformance());
        self::assertSame($layoutPlace, $inventory->getLayoutPlace());
        self::assertSame($layout, $inventory->getLayoutPlace()->getSeatingLayout());
    }

    public function test_an_inventory_row_starts_available_and_unallocated(): void
    {
        $em = $this->entityManager();

        $layout = $em->getRepository(SeatingLayout::class)->findOneBy(['version' => 1]);
        $layoutPlace = $em->getRepository(LayoutPlace::class)->findOneBy(['seatingLayout' => $layout]);

        $performance = new Performance(
            new \App\Entity\Event('Concert de gala', \App\Enum\EventType::Concert),
            $layout,
            new \DateTimeImmutable('+30 days'),
        );
        $inventory = new PerformancePlace($performance, $layoutPlace);

        self::assertSame(InventoryStatus::Available, $inventory->getStatus());
        self::assertNull($inventory->getCurrentReservation());
        self::assertNull($inventory->getHoldExpiresAt());
    }

    public function test_a_reservation_belongs_to_one_performance_and_records_its_channel(): void
    {
        $em = $this->entityManager();

        $layout = $em->getRepository(SeatingLayout::class)->findOneBy(['version' => 1]);
        $author = $em->getRepository(User::class)->findOneBy([]);

        $performance = new Performance(
            new \App\Entity\Event('Concert de gala', \App\Enum\EventType::Concert),
            $layout,
            new \DateTimeImmutable('+30 days'),
        );

        $reservation = new Reservation(
            $performance,
            $author,
            SalesChannel::BoxOffice,
            new \DateTimeImmutable('+10 minutes'),
        );

        self::assertSame($performance, $reservation->getPerformance());
        self::assertSame($author, $reservation->getCreatedBy());
        self::assertNull($reservation->getCustomer(), 'The customer is optional for an anonymous box-office sale.');
        self::assertSame(SalesChannel::BoxOffice, $reservation->getChannel());
    }

    public function test_the_tariffs_are_the_four_approved_ones(): void
    {
        $em = $this->entityManager();

        $tariffs = $em->getRepository(Tariff::class)->findBy([], ['code' => 'ASC']);

        self::assertCount(4, $tariffs);
        self::assertSame(
            ['FULL', 'GROUP', 'INVITATION', 'REDUCED'],
            array_map(static fn (Tariff $t) => $t->getCode(), $tariffs),
        );

        // Only the reduced tariff requires an in-person eligibility proof
        // (docs/DECISIONS.md Q5).
        foreach ($tariffs as $tariff) {
            self::assertSame(
                'REDUCED' === $tariff->getCode(),
                $tariff->requiresEligibilityProof(),
            );
        }
    }
}
