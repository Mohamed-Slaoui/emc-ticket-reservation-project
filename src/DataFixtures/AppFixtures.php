<?php

namespace App\DataFixtures;

use App\Entity\BookingPolicy;
use App\Entity\Event;
use App\Entity\LayoutPlace;
use App\Entity\LayoutZone;
use App\Entity\Performance;
use App\Entity\PerformancePlace;
use App\Entity\PerformancePrice;
use App\Entity\Place;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Entity\Tariff;
use App\Entity\Venue;
use App\Enum\EventStatus;
use App\Enum\EventType;
use App\Enum\PlaceType;
use App\Enum\TariffCode;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Entity\User;

/**
 * Level-1 demo data (docs/DECISIONS.md A6): one venue, one room,
 * 400 physical seats, one active seating configuration with three zones,
 * the four tariffs, the three demo accounts and the booking policy.
 * Fixed structure → reproducible tests.
 */
class AppFixtures extends Fixture
{
    public const DEMO_PASSWORD = 'demo1234';

    public const USER_ADMIN_EMAIL = 'admin@billetterie.demo';
    public const USER_BOX_OFFICE_EMAIL = 'caisse@billetterie.demo';

    /** The box-office account without any tariff permission — for the tests. */
    public const USER_BOX_OFFICE_LIMITED_EMAIL = 'caisse-limited@billetterie.demo';
    public const USER_CUSTOMER_EMAIL = 'client@billetterie.demo';

    public const ROWS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
    public const SEATS_PER_ROW = 40;

    /** Zones: rows A–F Orchestre (240), G–H Mezzanine (80), I–J Balcon (80). */
    private const ZONES = [
        'ORC' => ['name' => 'Orchestre', 'rows' => ['A', 'B', 'C', 'D', 'E', 'F']],
        'MEZ' => ['name' => 'Mezzanine', 'rows' => ['G', 'H']],
        'BAL' => ['name' => 'Balcon', 'rows' => ['I', 'J']],
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $venue = new Venue(
            'Théâtre Al-Mansour',
            '12, Avenue Mohammed V, Casablanca',
            'Salle de spectacle de 400 places : théâtre, concerts et conférences.',
        );
        $room = new Room($venue, 'Grande Salle', 'Salle principale — 10 rangées de 40 places.');
        $manager->persist($venue);
        $manager->persist($room);

        // Physical seats, independent of any configuration (§1.1).
        $placesByRow = [];
        foreach (self::ROWS as $row) {
            for ($seat = 1; $seat <= self::SEATS_PER_ROW; ++$seat) {
                $place = new Place(
                    $room,
                    sprintf('%s-%02d', $row, $seat),
                    PlaceType::Seat,
                    $row,
                    $seat,
                );
                $placesByRow[$row][$seat] = $place;
                $manager->persist($place);
            }
        }

        // Versioned configuration "Standard v1" mapping every seat (§C2).
        $layout = new SeatingLayout($room, 'Configuration Standard', 1, 'Répartition classique en trois zones.');
        $manager->persist($layout);

        $displayOrder = 1;
        foreach (self::ZONES as $zoneCode => $zoneDef) {
            $zone = new LayoutZone($layout, $zoneCode, $zoneDef['name'], sprintf("Zone %s de la Grande Salle.\nTarifs appliqués par zone.", $zoneDef['name']));
            $manager->persist($zone);

            foreach ($zoneDef['rows'] as $row) {
                for ($seat = 1; $seat <= self::SEATS_PER_ROW; ++$seat) {
                    $manager->persist(new LayoutPlace(
                        $layout,
                        $zone,
                        $placesByRow[$row][$seat],
                        $displayOrder++,
                    ));
                }
            }
        }

        // Pricing categories (docs/DECISIONS.md Q5).
        // The four approved pricing categories (docs/DECISIONS.md Q5). The
        // catalogue is fixed — the name is computed by the database from the code.
        $tariffFull = new Tariff(TariffCode::Full);
        $tariffReduced = new Tariff(TariffCode::Reduced); // supporting document verified in person
        $tariffGroup = new Tariff(TariffCode::Group); // BR-12: minimum 5 seats, enforced by services
        $tariffInvitation = new Tariff(TariffCode::Invitation);
        $manager->persist($tariffFull);
        $manager->persist($tariffReduced);
        $manager->persist($tariffGroup);
        $manager->persist($tariffInvitation);

        // A programme, still a draft: the administrator schedules its
        // performances (§4.1, steps 5 and 6). The demo configuration is
        // deliberately left editable — a configuration used by a
        // performance is frozen.
        $event = new Event('Soirée d\'ouverture', EventType::Theatre, 'Un spectacle de gala au théâtre.');
        $manager->persist($event);

        // Demo accounts (roles per docs/DECISIONS.md §3.8).
        $admin = $this->createUser(self::USER_ADMIN_EMAIL, 'Yassine', 'El Amrani', [User::ROLE_ADMIN]);
        $boxOffice = $this->createUser(self::USER_BOX_OFFICE_EMAIL, 'Fatima', 'Benali', [User::ROLE_BOX_OFFICE]);
        $boxOffice->setCanSellReducedTariff(true);
        $boxOffice->setCanSellInvitationTariff(true);

        $limited = $this->createUser(self::USER_BOX_OFFICE_LIMITED_EMAIL, 'Limited', 'Seller', [User::ROLE_BOX_OFFICE]);

        $customer = $this->createUser(self::USER_CUSTOMER_EMAIL, 'Karim', 'Ouazzani', [User::ROLE_CUSTOMER]);
        $manager->persist($admin);
        $manager->persist($boxOffice);
        $manager->persist($limited);
        $manager->persist($customer);

        // Booking policy (§3.1): 10-minute hold by default.
        $manager->persist(new BookingPolicy(10, $admin));

        // The rich demo dataset — dev only. The test suite reloads the
        // fixtures per test and relies on the earlier, deterministic set, so
        // the sample shows up only in the development environment.
        if ($this->isDev()) {
            $this->loadDevDemoData($manager, $tariffFull, $tariffReduced, $tariffGroup, $tariffInvitation, $venue, $layout);
        }

        $manager->flush();
    }

    /**
     * A full sample so the administration and the shop look alive out of the
     * box: two venues, three rooms over two layouts, a published programme
     * with sold-out and available shows, draft and archived events, the
     * accounts for every tariff-permission mix.
     *
     * Deliberately gated to the development environment — the test suite
     * reloads the fixtures per test and relies on the earlier deterministic
     * set (one venue, one room, one draft event).
     */
    private function loadDevDemoData(ObjectManager $manager, Tariff $tariffFull, Tariff $tariffReduced, Tariff $tariffGroup, Tariff $tariffInvitation, Venue $mainVenue, SeatingLayout $layoutGrande): void
    {
        // The base classes were persisted in memory only — flush once so
        // the catalogue can be indexed by code and the new shows can
        // reference it.
        $manager->flush();

        // --- A second venue, two rooms, two layouts -------------------------
        $maison = new Venue('Maison des arts', '8, Rue des arts, Casablanca', 'Studio dédié à la musique de chambre et aux spectacles pour le jeune public.');
        $petite = new Room($maison, 'Petite Salle', '140 places, 7 rangées de 20.');
        $lecture = new Room($maison, 'Salle de lecture', '90 places, 3 rangées de 30.');
        $manager->persist($maison);
        $manager->persist($petite);
        $manager->persist($lecture);

        $petiteSeats = $this->createSeatBlock($manager, $petite, 'ABCDEFG', 20, '');

        // --- The lecture hall seats ----------------------------------------
        $lectureSeats = [];

        foreach (['A', 'B', 'C'] as $row) {
            for ($seat = 1; $seat <= 30; ++$seat) {
                $place = new Place($lecture, sprintf('%s-%02d', $row, $seat), PlaceType::Seat, $row, $seat);
                $manager->persist($place);
                $lectureSeats[$row][$seat] = $place;
            }
        }

        // Layout for the Petite Salle (ORC / MEZ)
        $petiteLayout = new SeatingLayout($petite, 'Résidence', 1, 'Première configuration de la Petite Salle.');
        $manager->persist($petiteLayout);

        $displayOrder = 1;
        foreach (['ORC' => ['Orchestre', ['A', 'B', 'C', 'D']], 'MEZ' => ['Mezzanine', ['E', 'F', 'G']]] as $code => [$name, $rows]) {
            $zone = new LayoutZone($petiteLayout, $code, $name, $code === 'ORC' ? 'Proche de la scène.' : 'En fond de salle.');
            $manager->persist($zone);

            foreach ($rows as $row) {
                for ($seat = 1; $seat <= 20; ++$seat) {
                    $manager->persist(new LayoutPlace($petiteLayout, $zone, $petiteSeats[$row][$seat], $displayOrder++));
                }
            }
        }

        // Layout for the lecture hall (single zone, capacity 90)
        $lectureLayout = new SeatingLayout($lecture, 'Lecture', 1, 'Salle de lecture pour les rencontres.');
        $manager->persist($lectureLayout);
        $lectureZone = new LayoutZone($lectureLayout, 'ORC', 'Salle de lecture');
        $manager->persist($lectureZone);

        $displayOrder = 1;

        foreach (['A', 'B', 'C'] as $row) {
            for ($seat = 1; $seat <= 30; ++$seat) {
                $manager->persist(new LayoutPlace($lectureLayout, $lectureZone, $lectureSeats[$row][$seat], $displayOrder++));
            }
        }

        $studio = new Room($mainVenue, 'Salle Studio', 'Petite salle de 60 places, 5 rangées de 12.');
        $manager->persist($studio);

        $seatsOfStudio = $this->createSeatBlock($manager, $studio, 'ABCDE', 12, '');

        $studioLayout = new SeatingLayout($studio, 'Studio', 1, 'Toute la salle en résonance.');
        $manager->persist($studioLayout);

        $displayOrder = 1;
        foreach (['ORC' => ['Orchestre', ['A', 'B', 'C']], 'MEZ' => ['Mezzanine', ['D', 'E']]] as $code => [$name, $rows]) {
            $zone = new LayoutZone($studioLayout, $code, $name);
            $manager->persist($zone);

            foreach ($rows as $row) {
                for ($seat = 1; $seat <= 12; ++$seat) {
                    $manager->persist(new LayoutPlace($studioLayout, $zone, $seatsOfStudio[$row][$seat], $displayOrder++));
                }
            }
        }

        // --- Extra accounts for the demo -----------------------------------
        $vendor = $this->makeAccount('vendor@billetterie.demo', 'Omar', 'Bakkioui', [User::ROLE_BOX_OFFICE]);
        $vendor->setCanSellReducedTariff(true); // reduced only, no invitation
        $manager->persist($vendor);
        $manager->persist($this->makeAccount('souad@billetterie.demo', 'Souad', 'Hamid', [User::ROLE_CUSTOMER]));

        // --- Published events and performances ---------------------------------
        $manager->flush();

        $galaEvent = new Event('Soirée de gala', EventType::Theatre, "Une soirée de prestige sous les lumières de la Grande Salle.", EventStatus::Published);
        $manager->persist($galaEvent);
        $laScala = new Event('Concert de La Scala', EventType::Concert, 'Une symphonie pour cent réalisateurs au cœur de la salle de concerts.', EventStatus::Published);
        $manager->persist($laScala);
        $jazz = new Event('Nuit du jazz & du swing', EventType::Concert, 'Des standards en cuivres et contrebasse, tard dans la nuit.', EventStatus::Published);
        $manager->persist($jazz);
        $conte = new Event('Un conte pour les enfants', EventType::Other, "Une histoire merveilleuse pour les plus petits — en français et en darija.", EventStatus::Published);
        $manager->persist($conte);
        $doors = new Event('Journée des portes ouvertes', EventType::Conference, "Démonstrations, visites guidées et questions en direct.", EventStatus::Draft);
        $manager->persist($doors);
        $spring = new Event('Le printemps du théâtre', EventType::Theatre, 'La saison passée, en revue.', EventStatus::Archived);
        $manager->persist($spring);

        /** @var list<Performance> $shows */
        $shows = [];

        // Soirée de gala — two dates
        $shows[] = $this->createShow($manager, $galaEvent, $layoutGrande, new \DateTimeImmutable('2026-11-24T20:00:00'));
        $shows[] = $this->createShow($manager, $galaEvent, $layoutGrande, new \DateTimeImmutable('2026-12-10T20:00:00'));

        // Concert de La Scala — one date
        $shows[] = $this->createShow($manager, $laScala, $layoutGrande, new \DateTimeImmutable('2026-11-27T20:00:00'));

        // Nuit du jazz — Salle Studio
        $shows[] = $this->createShow($manager, $jazz, $studioLayout, new \DateTimeImmutable('2026-12-04T20:30:00'));

        // Un conte pour les enfants — Petite Salle
        $shows[] = $this->createShow($manager, $conte, $petiteLayout, new \DateTimeImmutable('2026-12-02T15:00:00'));

        // Portes ouvertes — Petite Salle, draft (the admin publishes from the UI)
        $draftShow = new Performance($doors, $petiteLayout, new \DateTimeImmutable('2026-12-05T10:00:00'), new \DateTimeImmutable('2026-12-05T12:00:00'));
        $manager->persist($draftShow);
        $this->copyLayoutMap($manager, $draftShow, $petiteLayout);
        $this->applyPrices($manager, $draftShow, $petiteLayout, ['ORC' => 45.0, 'MEZ' => 30.0], $tariffFull, $tariffReduced, $tariffGroup, $tariffInvitation);

        // Archived — season review
        $archivedShow = new Performance($spring, $layoutGrande, new \DateTimeImmutable('2027-03-14T20:00:00'), new \DateTimeImmutable('2027-03-14T22:00:00'));
        $manager->persist($archivedShow);
        $this->copyLayoutMap($manager, $archivedShow, $layoutGrande);
        $this->applyPrices($manager, $archivedShow, $layoutGrande, ['ORC' => 150.0, 'MEZ' => 110.0, 'BAL' => 80.0], $tariffFull, $tariffReduced, $tariffGroup, $tariffInvitation);

        foreach ([$shows[0], $shows[1], $shows[2]] as $show) {
            $this->applyPrices($manager, $show, $layoutGrande, ['ORC' => 150.0, 'MEZ' => 110.0, 'BAL' => 80.0], $tariffFull, $tariffReduced, $tariffGroup, $tariffInvitation);
        }

        $this->applyPrices($manager, $shows[3], $studioLayout, ['ORC' => 110.0, 'MEZ' => 80.0], $tariffFull, $tariffReduced, $tariffGroup, $tariffInvitation);
        $this->applyPrices($manager, $shows[4], $petiteLayout, ['ORC' => 60.0, 'MEZ' => 45.0], $tariffFull, $tariffReduced, $tariffGroup, $tariffInvitation);
    }

    /**
     * Creates the physical rows of seats and returns them grouped by row.
     *
     * @return array<string, array<int, Place>>
     */
    private function createSeatBlock(ObjectManager $manager, Room $room, string $rowLetters, int $seatsPerRow, string $prefix): array
    {
        $seatsByRow = [];

        for ($index = 0; $index < strlen($rowLetters); ++$index) {
            $row = $rowLetters[$index];

            for ($seat = 1; $seat <= $seatsPerRow; ++$seat) {
                $code = '' === $prefix
                    ? sprintf('%s-%02d', $row, $seat)
                    : sprintf('%s%s-%02d', $prefix, $row, $seat);
                $place = new Place($room, $code, PlaceType::Seat, $row, $seat);
                $manager->persist($place);
                $seatsByRow[$row][$seat] = $place;
            }
        }

        return $seatsByRow;
    }

    private function createShow(ObjectManager $manager, Event $event, SeatingLayout $layout, \DateTimeImmutable $startsAt): Performance
    {
        $performance = new Performance($event, $layout, $startsAt, $startsAt->modify('+2 hours'));

        $manager->persist($performance);

        $this->copyLayoutMap($manager, $performance, $layout);

        return $performance;
    }

    /**
     * Creates a corresponding PerformancePlace row for every LayoutPlace of the given configuration.
     */
    private function copyLayoutMap(ObjectManager $manager, Performance $performance, SeatingLayout $layout): void
    {
        /** @var list<LayoutPlace> $layoutPlaces */
        $layoutPlaces = $manager->getRepository(LayoutPlace::class)->findBy(['seatingLayout' => $layout], ['displayOrder' => 'ASC']);

        foreach ($layoutPlaces as $layoutPlace) {
            $manager->persist(new PerformancePlace($performance, $layoutPlace, !$layoutPlace->isAvailableInLayout()));
        }
    }

    /**
     * @param array<string, float> $fullByZone zone code => full-tariff price in MAD
     */
    private function applyPrices(
        ObjectManager $manager,
        Performance $performance,
        SeatingLayout $layout,
        array $fullByZone,
        Tariff $full,
        Tariff $reduced,
        Tariff $group,
        Tariff $invitation,
    ): void {
        $zoneByCode = [];

        /** @var list<LayoutZone> $zones */
        $zones = $manager->getRepository(LayoutZone::class)->findForLayout($layout);

        foreach ($zones as $zone) {
            $zoneByCode[$zone->getCode()] = $zone;
        }

        foreach ($fullByZone as $zoneCode => $fullPrice) {
            $zone = $zoneByCode[$zoneCode];

            $priceRows = [
                [$full, $fullPrice],
                [$reduced, round($fullPrice * 0.8, 2)],
                [$group, round($fullPrice * 0.75, 2)],
                [$invitation, 0.0],
            ];

            foreach ($priceRows as [$tariff, $amount]) {
                $manager->persist(new PerformancePrice(
                    $performance,
                    $zone,
                    $tariff,
                    number_format((float) $amount, 2, '.', ''),
                    'MAD',
                    1,
                ));
            }
        }
    }

    private function isDev(): bool
    {
        return ($_SERVER['APP_ENV'] ?? 'dev') !== 'test';
    }

    private function makeAccount(string $email, string $firstName, string $lastName, array $roles): User
    {
        return new User(
            $email,
            $this->passwordHasher->hashPassword(new User($email, 'placeholder', $firstName, $lastName), self::DEMO_PASSWORD),
            $firstName,
            $lastName,
            $roles,
        );
    }

    private function createUser(string $email, string $firstName, string $lastName, array $roles): User
    {
        $user = new User(
            $email,
            $this->passwordHasher->hashPassword(new User($email, 'placeholder', $firstName, $lastName), self::DEMO_PASSWORD),
            $firstName,
            $lastName,
            $roles,
        );

        return $user;
    }
}
