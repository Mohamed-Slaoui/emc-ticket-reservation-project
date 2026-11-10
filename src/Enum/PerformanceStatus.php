<?php

namespace App\Enum;

/**
 * Performance lifecycle.
 */
enum PerformanceStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Cancelled = 'CANCELLED';
    case Completed = 'COMPLETED';
}
