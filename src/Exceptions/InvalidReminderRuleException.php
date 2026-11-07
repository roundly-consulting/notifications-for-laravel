<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * A reminder rule that cannot be scheduled.
 */
final class InvalidReminderRuleException extends NotificationsException
{
    public static function invalid(string $type, string $reason): self
    {
        return self::make('invalid_reminder', 422, ['type' => $type, 'reason' => $reason]);
    }

    public static function unknownRule(string $type, string $rule): self
    {
        return self::make('invalid_reminder', 422, ['type' => $type, 'rule' => $rule], 'unknown_reminder_rule');
    }
}
