<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * How often a user receives the digest of low-priority, digestible notifications.
 */
#[TranslatedLabels('notifications::enums.digest_frequency')]
enum DigestFrequency: string
{
    use Helpers;

    case Off = 'off';
    case Daily = 'daily';
    case Weekly = 'weekly';
}
