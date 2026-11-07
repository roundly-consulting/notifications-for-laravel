<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * The life of one reminder or escalation row.
 */
#[TranslatedLabels('notifications::enums.reminder_status')]
enum ReminderStatus: string
{
    use Helpers;

    case Pending = 'pending';
    case Firing = 'firing';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Firing;
    }
}
