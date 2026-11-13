<?php

namespace App\Exception;

/**
 * Raised when a selected seat cannot enter a hold — it is not on sale for
 * this performance, or it changed hands since the screen was rendered.
 */
class SeatNotAvailableException extends ReservationEngineException
{
}
