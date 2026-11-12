<?php

namespace App\Service\Layout;

use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Exception\LayoutCannotBeModifiedException;
use App\Repository\LayoutPlaceRepository;
use App\Repository\LayoutZoneRepository;
use App\Repository\PerformanceRepository;
use App\Repository\RoomRepository;
use App\Repository\SeatingLayoutRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The seating layout: a **versioned** configuration of a room.
 *
 * The same room has several configurations — a concert, a conference — and
 * each of them has its own version. The version is unique within the room.
 *
 * A configuration that is already used by a performance is **frozen**: any
 * evolution creates a new version, so the past sales are never altered.
 */
class SeatingLayoutService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeatingLayoutRepository $layouts,
        private readonly LayoutZoneRepository $zones,
        private readonly LayoutPlaceRepository $layoutPlaces,
        private readonly RoomRepository $rooms,
        private readonly PerformanceRepository $performances,
    ) {
    }

    public function create(Room $room, string $name, ?string $description = null, bool $isActive = true): SeatingLayout
    {
        $layout = new SeatingLayout(
            $room,
            $name,
            $this->layouts->nextVersion($room),
            $description,
            $isActive,
        );

        $this->entityManager->persist($layout);
        $this->entityManager->flush();

        return $layout;
    }

    public function update(SeatingLayout $layout, string $name, ?string $description, bool $isActive): void
    {
        $this->assertIsEditable($layout);

        $layout->setName($name);
        $layout->setDescription($description);
        $layout->setIsActive($isActive);

        $this->entityManager->flush();
    }

    /**
     * Copies the configuration into a new version of the same room.
     *
     * The new version starts empty: the administrator maps the seats of the
     * new arrangement. It is the way to evolve a layout that is already in
     * use.
     */
    public function cloneAsNewVersion(SeatingLayout $layout, string $name, ?string $description = null): SeatingLayout
    {
        $clone = new SeatingLayout(
            $layout->getRoom(),
            $name,
            $this->layouts->nextVersion($layout->getRoom()),
            $description,
        );

        $this->entityManager->persist($clone);
        $this->entityManager->flush();

        return $clone;
    }

    /**
     * Deletes the configuration, its zones and its seat map.
     *
     * The seats leave the configuration with it: they are **mappings**, not
     * physical places — the places themselves stay in the room.
     *
     * @throws LayoutCannotBeModifiedException when the configuration is used by a performance
     */
    public function delete(SeatingLayout $layout): void
    {
        $this->remove($layout);

        $this->entityManager->flush();
    }

    /**
     * Removes the configuration, its zones and its seat map — without
     * flushing, so a cascade can delete a whole branch in one transaction.
     *
     * @throws LayoutCannotBeModifiedException when the configuration is used by a performance
     */
    public function remove(SeatingLayout $layout): void
    {
        $this->assertIsEditable($layout);

        if ($this->layoutPlaces->countUsedByPerformance($layout) > 0) {
            throw new LayoutCannotBeModifiedException(sprintf(
                'The configuration "%s" (version %d) holds seats that a performance has already sold: it can no longer be deleted.',
                $layout->getName(),
                $layout->getVersion(),
            ));
        }

        // The seats go first: they reference both the zones and the
        // configuration.
        foreach ($this->layoutPlaces->findForLayout($layout) as $layoutPlace) {
            $this->entityManager->remove($layoutPlace);
        }

        // Then the zones, which reference the configuration.
        foreach ($this->zones->findForLayout($layout) as $zone) {
            $this->entityManager->remove($zone);
        }

        $this->entityManager->remove($layout);
    }

    /**
     * Whether the configuration is already used by a performance.
     */
    public function isUsedByPerformance(SeatingLayout $layout): bool
    {
        return $this->performances->countForLayout($layout) > 0;
    }

    /**
     * @throws LayoutCannotBeModifiedException
     */
    public function assertIsEditable(SeatingLayout $layout): void
    {
        if ($this->isUsedByPerformance($layout)) {
            throw new LayoutCannotBeModifiedException(sprintf(
                'The configuration "%s" (version %d) is used by a performance: it can no longer be edited. Create a new version instead.',
                $layout->getName(),
                $layout->getVersion(),
            ));
        }
    }
}
