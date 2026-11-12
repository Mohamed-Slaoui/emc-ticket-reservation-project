<?php

namespace App\DataFixtures;

use App\Entity\BookingPolicy;
use App\Entity\Event;
use App\Entity\LayoutPlace;
use App\Entity\LayoutZone;
use App\Entity\Place;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Entity\Tariff;
use App\Entity\Venue;
use App\Enum\EventType;
use App\Enum\PlaceType;
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
        $manager->persist(new Tariff('PLEIN', 'Plein tarif'));
        $manager->persist(new Tariff('REDUIT', 'Tarif réduit', true)); // supporting document verified in person
        $manager->persist(new Tariff('GROUPE', 'Tarif groupe')); // BR-12: minimum 5 seats, enforced by services
        $manager->persist(new Tariff('INVITATION', 'Invitation'));

        // A programme, still a draft: the administrator schedules its
        // performances (§4.1, steps 5 and 6). The demo configuration is
        // deliberately left editable — a configuration used by a
        // performance is frozen.
        $event = new Event('Soirée de gala', EventType::Theatre, 'Un spectacle de gala au théâtre.');
        $manager->persist($event);

        // Demo accounts (roles per docs/DECISIONS.md §3.8).
        $admin = $this->createUser(self::USER_ADMIN_EMAIL, 'Yassine', 'El Amrani', [User::ROLE_ADMIN]);
        $boxOffice = $this->createUser(self::USER_BOX_OFFICE_EMAIL, 'Fatima', 'Benali', [User::ROLE_BOX_OFFICE]);
        $customer = $this->createUser(self::USER_CUSTOMER_EMAIL, 'Karim', 'Ouazzani', [User::ROLE_CUSTOMER]);
        $manager->persist($admin);
        $manager->persist($boxOffice);
        $manager->persist($customer);

        // Booking policy (§3.1): 10-minute hold by default.
        $manager->persist(new BookingPolicy(10, $admin));

        $manager->flush();
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
