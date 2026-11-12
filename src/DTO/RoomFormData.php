<?php

namespace App\DTO;

use App\Entity\Venue;

/**
 * The data carried by the room form.
 */
final class RoomFormData
{
    public ?Venue $venue = null;

    public string $name = '';

    public ?string $description = null;
}
