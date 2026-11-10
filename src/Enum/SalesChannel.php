<?php

namespace App\Enum;

/**
 * Sales channel. Online and box-office sales share the same
 * availability engine (docs/MODELE.md §1.4) — the channel is an
 * attribute, not a parallel model.
 */
enum SalesChannel: string
{
    case Online = 'ONLINE';
    case BoxOffice = 'BOX_OFFICE';
}
