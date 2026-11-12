<?php

namespace App\Service\Layout;

use App\Entity\LayoutPlace;
use App\Entity\LayoutZone;
use App\Entity\Place;
use App\Entity\SeatingLayout;
use App\Exception\LayoutCannotBeModifiedException;
use App\Repository\LayoutPlaceRepository;
use App\Repository\PlaceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The seat map of a configuration: which physical place belongs to which
 * zone.
 *
 * A place appears **once** per configuration, and it must be a place of the
 * room the configuration describes. Both rules are enforced here; the
 * database also refuses a duplicate seat.
 */
class LayoutPlaceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LayoutPlaceRepository $layoutPlaces,
        private readonly PlaceRepository $places,
        private readonly SeatingLayoutService $layouts,
    ) {
    }

    /**
     * Maps the given places to the given zone.
     *
     * A place that is already mapped in the configuration **moves** to the
     * new zone; a place that is not mapped yet is added.
     *
     * @param list<int> $placeIds
     *
     * @return int the number of places mapped
     *
     * @throws LayoutCannotBeModifiedException when the configuration is used by a performance
     */
    public function assign(SeatingLayout $layout, LayoutZone $zone, array $placeIds): int
    {
        $this->layouts->assertIsEditable($layout);

        if ($zone->getSeatingLayout()->getId() !== $layout->getId()) {
            throw new \InvalidArgumentException('The zone belongs to another configuration.');
        }

        $order = $this->roomOrder($layout);

        $assigned = 0;

        foreach ($this->placesIn($layout, $placeIds) as $place) {
            $layoutPlace = $this->layoutPlaces->findOneBy([
                'seatingLayout' => $layout,
                'place' => $place,
            ]);

            if (null === $layoutPlace) {
                $layoutPlace = new LayoutPlace($layout, $zone, $place, $order[(int) $place->getId()] ?? 0);
                $this->entityManager->persist($layoutPlace);
            } else {
                $layoutPlace->moveToZone($zone);
            }

            ++$assigned;
        }

        $this->entityManager->flush();

        return $assigned;
    }

    /**
     * Removes the given places from the configuration.
     *
     * The physical places are **not** deleted: they go back to the unmapped
     * seats of the room.
     *
     * @param list<int> $placeIds
     *
     * @return int the number of places released
     *
     * @throws LayoutCannotBeModifiedException when the configuration is used by a performance
     */
    public function unassign(SeatingLayout $layout, array $placeIds): int
    {
        $this->layouts->assertIsEditable($layout);

        $released = 0;

        foreach ($this->placesIn($layout, $placeIds) as $place) {
            $layoutPlace = $this->layoutPlaces->findOneBy([
                'seatingLayout' => $layout,
                'place' => $place,
            ]);

            if (null === $layoutPlace) {
                continue;
            }

            $this->entityManager->remove($layoutPlace);
            ++$released;
        }

        $this->entityManager->flush();

        return $released;
    }

    /**
     * The places of the room of the configuration, in their physical order.
     *
     * @param list<int> $placeIds
     *
     * @return list<Place>
     */
    private function placesIn(SeatingLayout $layout, array $placeIds): array
    {
        if ([] === $placeIds) {
            return [];
        }

        /** @var list<Place> $places */
        $places = $this->places->findBy(['id' => $placeIds]);

        // A place must belong to the room the configuration describes.
        $roomId = $layout->getRoom()->getId();

        return array_values(array_filter(
            $places,
            static fn (Place $place): bool => $place->getRoom()->getId() === $roomId,
        ));
    }

    /**
     * The physical order of the places of the room, used as the display
     * order of the seats.
     *
     * @return array<int, int>
     */
    private function roomOrder(SeatingLayout $layout): array
    {
        $order = [];

        foreach ($this->places->findForRoom($layout->getRoom()) as $index => $place) {
            $order[(int) $place->getId()] = $index;
        }

        return $order;
    }
}
