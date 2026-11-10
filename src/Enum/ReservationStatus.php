<?php

namespace App\Enum;

/**
 * Reservation lifecycle (docs/MODELE.md §7.1):
 * HELD → CONFIRMED | EXPIRED | CANCELLED; CONFIRMED → CANCELLED.
 * EXPIRED and CANCELLED are terminal.
 */
enum ReservationStatus: string
{
    case Held = 'HELD';
    case Confirmed = 'CONFIRMED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
}
