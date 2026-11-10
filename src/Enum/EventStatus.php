<?php

namespace App\Enum;

/**
 * Event lifecycle: draft until administration publishes it.
 */
enum EventStatus: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
    case Archived = 'ARCHIVED';
}
