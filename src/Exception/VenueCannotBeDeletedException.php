<?php

namespace App\Exception;

/**
 * Raised when a venue cannot be deleted because it still contains rooms.
 */
class VenueCannotBeDeletedException extends \RuntimeException
{
}
