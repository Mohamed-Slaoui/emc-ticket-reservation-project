<?php

namespace App\Service\Layout;

use App\Entity\LayoutZone;
use App\Entity\SeatingLayout;
use App\Exception\LayoutCannotBeModifiedException;
use App\Exception\ZoneCodeAlreadyExistsException;
use App\Repository\LayoutPlaceRepository;
use App\Repository\LayoutZoneRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The zones of a configuration.
 *
 * A zone groups the seats that share the same price. Its code is unique
 * within the configuration.
 */
class LayoutZoneService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LayoutZoneRepository $zones,
        private readonly LayoutPlaceRepository $layoutPlaces,
        private readonly SeatingLayoutService $layouts,
    ) {
    }

    /**
     * @throws ZoneCodeAlreadyExistsException when the code is already used in the configuration
     */
    public function create(SeatingLayout $layout, string $code, string $name, ?string $description = null): LayoutZone
    {
        $this->layouts->assertIsEditable($layout);
        $this->assertCodeIsFree($layout, $code);

        $zone = new LayoutZone($layout, $code, $name, $description);

        $this->entityManager->persist($zone);
        $this->entityManager->flush();

        return $zone;
    }

    /**
     * @throws ZoneCodeAlreadyExistsException when the code is already used by another zone
     */
    public function update(LayoutZone $zone, string $code, string $name, ?string $description): void
    {
        $this->layouts->assertIsEditable($zone->getSeatingLayout());

        if ($code !== $zone->getCode()) {
            $this->assertCodeIsFree($zone->getSeatingLayout(), $code);
        }

        $zone->setCode($code);
        $zone->setName($name);
        $zone->setDescription($description);

        $this->entityManager->flush();
    }

    /**
     * Deletes the zone and releases the seats it held.
     *
     * The seats are **not** deleted: they go back to the unmapped seats of
     * the room, so the administrator can map them to another zone.
     *
     * @throws LayoutCannotBeModifiedException when the configuration is used by a performance
     */
    public function delete(LayoutZone $zone): void
    {
        $this->layouts->assertIsEditable($zone->getSeatingLayout());

        // The seats leave the configuration: they become unmapped again.
        foreach ($this->layoutPlaces->findForZone($zone) as $layoutPlace) {
            $this->entityManager->remove($layoutPlace);
        }

        $this->entityManager->remove($zone);
        $this->entityManager->flush();
    }

    /**
     * @throws ZoneCodeAlreadyExistsException
     */
    private function assertCodeIsFree(SeatingLayout $layout, string $code): void
    {
        if ($this->zones->existsCodeInLayout($layout, $code)) {
            throw new ZoneCodeAlreadyExistsException(sprintf(
                'The code "%s" is already used by another zone of this configuration.',
                $code,
            ));
        }
    }
}
