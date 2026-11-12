<?php

namespace App\Service\Venue;

use App\Entity\Room;
use App\Entity\Venue;
use App\Exception\RoomCannotBeDeletedException;
use App\Repository\PlaceRepository;
use App\Repository\RoomRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A room of the venue, and the seats it holds.
 */
class RoomService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RoomRepository $rooms,
        private readonly PlaceRepository $places,
    ) {
    }

    public function create(Venue $venue, string $name, ?string $description = null): Room
    {
        $room = new Room($venue, $name, $description);

        $this->entityManager->persist($room);
        $this->entityManager->flush();

        return $room;
    }

    public function update(Room $room, string $name, ?string $description = null): void
    {
        $room->setName($name);
        $room->setDescription($description);

        $this->entityManager->flush();
    }

    /**
     * @throws RoomCannotBeDeletedException when the room still holds seats
     */
    public function delete(Room $room): void
    {
        $placeCount = $this->places->countForRoom($room);

        if ($placeCount > 0) {
            throw new RoomCannotBeDeletedException(sprintf(
                'This room still holds %d place%s: delete the places first, or keep the room as it is.',
                $placeCount,
                $placeCount > 1 ? 's' : '',
            ));
        }

        $this->entityManager->remove($room);
        $this->entityManager->flush();
    }
}
