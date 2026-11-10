<?php

namespace App\Enum;

/**
 * Payment attempt state. Multiple attempts may exist for one reservation;
 * a single successful transaction covers the full amount in the MVP.
 */
enum PaymentStatus: string
{
    case Pending = 'PENDING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
}
