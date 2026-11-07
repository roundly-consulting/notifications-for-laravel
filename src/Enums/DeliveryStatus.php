<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * The life of one mail delivery row.
 */
#[TranslatedLabels('notifications::enums.delivery_status')]
enum DeliveryStatus: string
{
    use Helpers;

    case Pending = 'pending';
    case Held = 'held';
    case Digest = 'digest';
    case Sending = 'sending';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Undeliverable = 'undeliverable';

    /**
     * Not sent yet: a later event in the window folds into it.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Held, self::Digest], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Sent, self::Cancelled, self::Failed, self::Undeliverable], true);
    }
}
