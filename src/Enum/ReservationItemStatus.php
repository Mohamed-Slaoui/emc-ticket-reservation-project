<?php

namespace App\Enum;

/**
 * Reservation item lifecycle — the seat inside its reservation.
 * The entity's status() transitions follow the reservation lifecycle.
 */
enum ReservationItemStatus: string
{
    case Held = 'HELD';
    case Confirmed = 'CONFIRMED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
}
