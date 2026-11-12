<?php

namespace App\Exception;

/**
 * Raised when a room cannot be deleted because it still holds seats.
 */
class RoomCannotBeDeletedException extends \RuntimeException
{
}
