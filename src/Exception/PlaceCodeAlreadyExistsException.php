<?php

namespace App\Exception;

/**
 * Raised when a place is created or renamed with a code that is already
 * used by another place of the same room.
 */
class PlaceCodeAlreadyExistsException extends \RuntimeException
{
}
