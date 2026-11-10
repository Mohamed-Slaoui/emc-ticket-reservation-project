<?php

namespace App\Enum;

/**
 * Inventory state of a sellable seat for a given performance.
 *
 * See docs/MODELE.md §5.4 for the state invariant
 * (status / currentReservation / holdExpiresAt consistency).
 */
enum InventoryStatus: string
{
    case Available = 'AVAILABLE';
    case Held = 'HELD';
    case Sold = 'SOLD';
    case Blocked = 'BLOCKED';
}
