<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Leading: the first event of a window mails, later ones fold into it. Trailing: one mail
 * after the window closes, with the final count.
 */
#[TranslatedLabels('notifications::enums.mail_strategy')]
enum MailStrategy: string
{
    use Helpers;

    case Leading = 'leading';
    case Trailing = 'trailing';
}
