<?php

namespace App\Service\Venue;

use App\Entity\Place;
use App\Entity\Room;
use App\Enum\PlaceType;
use App\Exception\PlaceCannotBeDeletedException;
use App\Exception\PlaceCodeAlreadyExistsException;
use App\Repository\LayoutPlaceRepository;
use App\Repository\PlaceRepository;
use App\Service\Layout\SeatingLayoutService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The physical places of a room: numbered seats and standing slots.
 *
 * A place that is mapped in a seating layout leaves the configuration when
 * it is deleted — unless the configuration is used by a performance: the
 * seat was sold, and the sale must survive.
 */
class PlaceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlaceRepository $places,
        private readonly LayoutPlaceRepository $layoutPlaces,
        private readonly SeatingLayoutService $layouts,
    ) {
    }

    /**
     * @throws PlaceCodeAlreadyExistsException when the code is already used in the room
     */
    public function create(
        Room $room,
        string $code,
        PlaceType $type = PlaceType::Seat,
        ?string $rowLabel = null,
        ?int $seatNumber = null,
        bool $isActive = true,
    ): Place {
        $this->assertCodeIsFree($room, $code);

        $place = new Place($room, $code, $type, $rowLabel, $seatNumber, $isActive);

        $this->entityManager->persist($place);
        $this->entityManager->flush();

        return $place;
    }

    /**
     * @throws PlaceCodeAlreadyExistsException when the code is already used in the room
     */
    public function update(
        Place $place,
        string $code,
        PlaceType $type,
        ?string $rowLabel,
        ?int $seatNumber,
        bool $isActive,
    ): void {
        if ($code !== $place->getCode()) {
            $this->assertCodeIsFree($place->getRoom(), $code);
        }

        $place->setCode($code);
        $place->setType($type);
        $place->setRowLabel($rowLabel);
        $place->setSeatNumber($seatNumber);
        $place->setIsActive($isActive);

        $this->entityManager->flush();
    }

    /**
     * The code identifies the place inside its room, and it is unique there.
     *
     * @throws PlaceCodeAlreadyExistsException
     */
    private function assertCodeIsFree(Room $room, string $code): void
    {
        if ($this->places->existsInRoom($room, $code)) {
            throw new PlaceCodeAlreadyExistsException(sprintf(
                'The code "%s" is already used in the room "%s".',
                $code,
                $room->getName(),
            ));
        }
    }

    public function setActive(Place $place, bool $active): void
    {
        $place->setIsActive($active);

        $this->entityManager->flush();
    }

    /**
     * Deletes the place and the mappings it holds in the seating layouts.
     *
     * @throws PlaceCannotBeDeletedException when the seat was sold for a performance
     */
    public function delete(Place $place): void
    {
        $this->remove($place);

        $this->entityManager->flush();
    }

    /**
     * Removes the place and its mappings — without flushing, so a cascade
     * can delete a whole branch in one transaction.
     *
     * @throws PlaceCannotBeDeletedException when the seat was sold for a performance
     */
    public function remove(Place $place): void
    {
        // The place leaves the configurations it was mapped in.
        foreach ($this->layoutPlaces->findForPlace($place) as $layoutPlace) {
            $layout = $layoutPlace->getSeatingLayout();

            if ($this->layouts->isUsedByPerformance($layout)) {
                throw new PlaceCannotBeDeletedException(sprintf(
                    'This place belongs to the configuration "%s", which is used by a performance: it was sold and cannot be deleted.',
                    $layout->getName(),
                ));
            }

            $this->entityManager->remove($layoutPlace);
        }

        $this->entityManager->remove($place);
    }

    /**
     * Creates a block of places at once — a venue holds hundreds of seats,
     * and entering them one by one would be unusable.
     *
     * The codes follow the convention of the demo data: `A-01`, `A-02`, …
     * for the seats of a row, and `ST-001`, `ST-002`, … for the standing
     * slots.
     *
     * The places that already exist in the room are **skipped**, not refused:
     * the administrator is filling a row that is partly there.
     *
     * @return array{created: int, skipped: int}
     */
    public function createBlock(
        Room $room,
        string $rowLabel,
        int $from,
        int $to,
        PlaceType $type = PlaceType::Seat,
        string $codePrefix = '',
    ): array {
        $existing = $this->places->findCodesForRoom($room);

        $created = 0;
        $skipped = 0;

        foreach (range($from, $to) as $number) {
            $code = $this->buildCode($codePrefix, $rowLabel, $number, $type);

            if (in_array($code, $existing, true)) {
                ++$skipped;
                continue;
            }

            $place = new Place(
                $room,
                $code,
                $type,
                '' === $rowLabel ? null : $rowLabel,
                $number,
            );

            $this->entityManager->persist($place);
            $existing[] = $code;
            ++$created;
        }

        $this->entityManager->flush();

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * `A-01` for the seats of a row, `ST-001` for the standing slots.
     */
    private function buildCode(string $prefix, string $rowLabel, int $number, PlaceType $type): string
    {
        $prefix = trim($prefix);
        $rowLabel = trim($rowLabel);

        if ('' === $rowLabel) {
            return $prefix.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
        }

        return $prefix.$rowLabel.'-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
    }
}
