<?php

namespace App\Service\Venue;

use App\Entity\Room;
use App\Entity\Venue;
use App\Exception\LayoutCannotBeModifiedException;
use App\Exception\RoomCannotBeDeletedException;
use App\Repository\PlaceRepository;
use App\Repository\RoomRepository;
use App\Repository\SeatingLayoutRepository;
use App\Service\Layout\SeatingLayoutService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A room of the venue, and the seats it holds.
 *
 * Deleting a room deletes what it holds: its seating configurations and its
 * physical seats. The only thing that survives is what a performance has
 * already sold.
 */
class RoomService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RoomRepository $rooms,
        private readonly PlaceRepository $places,
        private readonly SeatingLayoutRepository $layouts,
        private readonly SeatingLayoutService $layoutService,
        private readonly PlaceService $placeService,
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
     * Deletes the room, its seating configurations and its seats.
     *
     * @throws RoomCannotBeDeletedException when a configuration is used by a performance
     */
    public function delete(Room $room): void
    {
        $this->remove($room);

        $this->entityManager->flush();
    }

    /**
     * Removes the room and what it holds — without flushing, so a cascade
     * can delete a whole branch in one transaction.
     *
     * @throws RoomCannotBeDeletedException when a configuration is used by a performance
     */
    public function remove(Room $room): void
    {
        // The configurations of the room go with it, and the seats they map.
        foreach ($this->layouts->findForRoom($room) as $layout) {
            try {
                $this->layoutService->remove($layout);
            } catch (LayoutCannotBeModifiedException $exception) {
                throw new RoomCannotBeDeletedException($exception->getMessage(), 0, $exception);
            }
        }

        // Then the physical seats of the room.
        foreach ($this->places->findForRoom($room) as $place) {
            $this->placeService->remove($place);
        }

        $this->entityManager->remove($room);
    }
}
