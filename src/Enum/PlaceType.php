<?php

namespace App\Enum;

/**
 * Physical seat type: numbered seat or standing slot.
 *
 * See docs/MODELE.md §4.4 (Q2): a standing zone is represented by
 * anonymous slots that reuse the same allocation mechanism as seats.
 */
enum PlaceType: string
{
    case Seat = 'SEAT';
    case StandingSlot = 'STANDING_SLOT';
}
