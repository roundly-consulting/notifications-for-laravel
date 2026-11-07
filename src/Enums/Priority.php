<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Low types may go to a digest; urgent types bypass quiet hours, digests and trailing mail.
 */
#[TranslatedLabels('notifications::enums.priority')]
enum Priority: string
{
    use Helpers;

    case Low = 'low';
    case Normal = 'normal';
    case Urgent = 'urgent';
}
