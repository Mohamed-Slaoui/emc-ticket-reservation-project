<?php

namespace App\Exception;

/**
 * Raised when a place cannot be deleted because it is already mapped in a
 * seating layout.
 */
class PlaceCannotBeDeletedException extends \RuntimeException
{
}
