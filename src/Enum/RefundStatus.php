<?php

namespace App\Enum;

/**
 * Refund state — independent of the payment state (a payment can be
 * SUCCEEDED while its refund is still PENDING).
 */
enum RefundStatus: string
{
    case Pending = 'PENDING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
}
