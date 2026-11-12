<?php

namespace App\Exception;

/**
 * Raised when the schedule of a performance is not coherent: the show ends
 * before it starts, or the sale closes after the show has begun.
 */
class InvalidPerformanceScheduleException extends \RuntimeException
{
}
