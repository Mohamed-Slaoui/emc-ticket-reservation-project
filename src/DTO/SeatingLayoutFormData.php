<?php

namespace App\DTO;

use App\Entity\Room;

/**
 * The data carried by the seating layout form.
 *
 * The form does not map the entity directly: its constructor requires
 * arguments, and the form owns the values until the service saves them.
 */
final class SeatingLayoutFormData
{
    public ?Room $room = null;

    public string $name = '';

    public ?string $description = null;

    public bool $isActive = true;
}
