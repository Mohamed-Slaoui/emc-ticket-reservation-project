<?php

namespace App\Enum;

/**
 * Nature of the event: concert, theatre, conference, other.
 */
enum EventType: string
{
    case Concert = 'CONCERT';
    case Theatre = 'THEATRE';
    case Conference = 'CONFERENCE';
    case Other = 'OTHER';
}
