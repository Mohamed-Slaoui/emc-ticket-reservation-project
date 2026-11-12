<?php

namespace App\Exception;

/**
 * Raised when a performance cannot be scheduled because its room is already
 * occupied during that interval.
 *
 * The room is reached through the configuration: two performances of two
 * different configurations of the same room conflict just as much as two
 * performances of the same configuration.
 */
class PerformanceRoomUnavailableException extends \RuntimeException
{
}
