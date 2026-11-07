<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Enums;

/**
 * The life of one digest run (one recipient, frequency and period).
 */
enum DigestRunStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Empty = 'empty';
    case Failed = 'failed';
}
