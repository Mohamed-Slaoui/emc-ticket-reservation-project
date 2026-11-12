<?php

namespace App\Exception;

/**
 * Raised when an event cannot be deleted because a performance already
 * holds a sale.
 */
class EventCannotBeDeletedException extends \RuntimeException
{
}
