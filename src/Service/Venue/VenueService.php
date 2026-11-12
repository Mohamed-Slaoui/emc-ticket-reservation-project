<?php

namespace App\Service\Venue;

use App\Entity\Venue;
use App\Exception\VenueCannotBeDeletedException;
use App\Repository\RoomRepository;
use App\Repository\VenueRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The physical venue: its identity and its address.
 *
 * A venue that already contains rooms cannot be deleted — the rooms, and
 * through them the seats and the sales, must survive.
 */
class VenueService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VenueRepository $venues,
        private readonly RoomRepository $rooms,
    ) {
    }

    public function create(string $name, string $address, ?string $description = null): Venue
    {
        $venue = new Venue($name, $address, $description);

        $this->entityManager->persist($venue);
        $this->entityManager->flush();

        return $venue;
    }

    public function update(Venue $venue, string $name, string $address, ?string $description = null): void
    {
        $venue->setName($name);
        $venue->setAddress($address);
        $venue->setDescription($description);

        $this->entityManager->flush();
    }

    /**
     * @throws VenueCannotBeDeletedException when the venue still contains rooms
     */
    public function delete(Venue $venue): void
    {
        $roomCount = $this->rooms->countForVenue($venue);

        if ($roomCount > 0) {
            throw new VenueCannotBeDeletedException(sprintf(
                'This venue still contains %d room%s: delete the rooms first, or keep the venue as it is.',
                $roomCount,
                $roomCount > 1 ? 's' : '',
            ));
        }

        $this->entityManager->remove($venue);
        $this->entityManager->flush();
    }
}
