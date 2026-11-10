<?php

namespace App\Enum;

/**
 * Ticket lifecycle (docs/MODELE.md §7.3):
 * VALID → USED | VOID; USED and VOID are terminal.
 * A ticket is never reactivated.
 */
enum TicketStatus: string
{
    case Valid = 'VALID';
    case Used = 'USED';
    case Void = 'VOID';
}
