<?php

namespace App\Exception;

/**
 * Raised when the reservation cannot be created or its state must not change.
 */
class ReservationEngineException extends \RuntimeException
{
}
