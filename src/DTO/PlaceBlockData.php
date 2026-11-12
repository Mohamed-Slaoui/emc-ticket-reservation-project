<?php

namespace App\DTO;

use App\Entity\Room;
use App\Enum\PlaceType;

/**
 * The data of the "add a block of places" form.
 */
final class PlaceBlockData
{
    /** The room the places are added to. */
    public ?Room $room = null;

    /** The row the places belong to — empty for the standing slots. */
    public string $rowLabel = '';

    public int $from = 1;

    public int $to = 10;

    /** The type is kept as its **backing value** — the form works with scalars. */
    public string $type = PlaceType::Seat->value;

    /** Prepended to every generated code — `ST-` for the standing slots. */
    public string $codePrefix = '';

    public function getPlaceType(): PlaceType
    {
        return PlaceType::tryFrom($this->type) ?? PlaceType::Seat;
    }
}
