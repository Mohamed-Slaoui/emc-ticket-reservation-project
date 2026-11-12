<?php

namespace App\Exception;

/**
 * Raised when a performance cannot be deleted because it already holds a
 * sale.
 */
class PerformanceCannotBeDeletedException extends \RuntimeException
{
}
