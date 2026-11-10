<?php

namespace App\Enum;

/**
 * Integration event state for the transactional outbox
 * (Google Sheets synchronization, docs/DECISIONS.md §3.7).
 */
enum OutboxStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Sent = 'SENT';
    case Failed = 'FAILED';
}
