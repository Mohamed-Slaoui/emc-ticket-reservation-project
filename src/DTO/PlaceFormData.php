<?php

namespace App\DTO;

use App\Entity\Room;
use App\Enum\PlaceType;

/**
 * The data carried by the place form.
 *
 * The type is kept as its **backing value** (`SEAT`, `STANDING_SLOT`): the
 * choice list of a form works with scalars, and the enum is rebuilt when the
 * place is saved.
 */
final class PlaceFormData
{
    public ?Room $room = null;

    public string $code = '';

    public string $type = PlaceType::Seat->value;

    public string $rowLabel = '';

    public ?int $seatNumber = null;

    public bool $isActive = true;

    /**
     * The row of the place, or null when it has none — the standing slots
     * belong to no row.
     */
    public function getRowLabel(): ?string
    {
        return '' !== $this->rowLabel ? $this->rowLabel : null;
    }

    public function getPlaceType(): PlaceType
    {
        return PlaceType::tryFrom($this->type) ?? PlaceType::Seat;
    }
}
