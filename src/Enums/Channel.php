<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Where a notification is delivered: the in-app inbox or email.
 */
#[TranslatedLabels('notifications::enums.channel')]
enum Channel: string
{
    use Helpers;

    case Inbox = 'inbox';
    case Mail = 'mail';
}
