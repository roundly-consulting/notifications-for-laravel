<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * Recipients that must be stored (a scheduled reminder, an escalation, a queued fan-out) but are
 * defined by a closure.
 */
final class UnstorableRecipientsException extends NotificationsException
{
    public static function closure(): self
    {
        return self::make('unstorable_recipients', 422);
    }

    public static function resolver(string $resolver): self
    {
        return self::make('unstorable_recipients', 422, ['resolver' => $resolver], 'unstorable_resolver');
    }
}
