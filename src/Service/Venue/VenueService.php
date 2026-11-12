<?php

namespace App\Service\Venue;

use App\Entity\Venue;
use App\Exception\LayoutCannotBeModifiedException;
use App\Exception\RoomCannotBeDeletedException;
use App\Exception\VenueCannotBeDeletedException;
use App\Repository\RoomRepository;
use App\Repository\VenueRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The physical venue: its identity and its address.
 *
 * Deleting a venue deletes what it holds: its rooms, their seating
 * configurations and their seats. The only thing that survives is what a
 * performance has already sold.
 */
class VenueService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VenueRepository $venues,
        private readonly RoomRepository $rooms,
        private readonly RoomService $roomService,
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
     * Deletes the venue, its rooms, their configurations and their seats.
     *
     * @throws VenueCannotBeDeletedException when a configuration is used by a performance
     */
    public function delete(Venue $venue): void
    {
        foreach ($this->rooms->findForVenue($venue) as $room) {
            try {
                $this->roomService->remove($room);
            } catch (RoomCannotBeDeletedException $exception) {
                throw new VenueCannotBeDeletedException($exception->getMessage(), 0, $exception);
            } catch (LayoutCannotBeModifiedException $exception) {
                throw new VenueCannotBeDeletedException($exception->getMessage(), 0, $exception);
            }
        }

        $this->entityManager->remove($venue);
        $this->entityManager->flush();
    }
}
