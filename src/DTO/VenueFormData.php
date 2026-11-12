<?php

namespace App\DTO;

/**
 * The data carried by the venue form.
 *
 * The form does not map the entity directly: its constructor requires
 * arguments, and the form owns the values until the service saves them.
 */
final class VenueFormData
{
    public string $name = '';

    public string $address = '';

    public ?string $description = null;
}
