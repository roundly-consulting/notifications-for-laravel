<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

use Carbon\CarbonInterval;

/**
 * A reminder rule — or a send-level escalation — that cannot be scheduled.
 */
final class InvalidReminderRuleException extends NotificationsException
{
    public static function invalid(string $type, string $reason): self
    {
        return self::make('invalid_reminder', 422, ['type' => $type, 'reason' => $reason]);
    }

    /**
     * A repeating rule (`Reminder::every()`) built wrong: an unreadable interval, a `times()`
     * below one, a repeat-only setting on a one-off rule, or an escalation by count on one.
     */
    public static function invalidRepeat(string $reason): self
    {
        return self::make('invalid_reminder', 422, ['reason' => $reason], 'invalid_repeat');
    }

    /**
     * A snooze that cannot apply: an instant not in the future, or a type without a repeating rule.
     */
    public static function invalidSnooze(string $type, string $reason): self
    {
        return self::make('invalid_reminder', 422, ['type' => $type, 'reason' => $reason], 'invalid_snooze');
    }

    /**
     * An escalation level whose `type:` is not registered, or cannot take the escalated subject.
     */
    public static function escalationType(string $type, string $escalation): self
    {
        return self::make('invalid_reminder', 422, ['type' => $type, 'escalation' => $escalation], 'invalid_escalation_type');
    }

    /**
     * An escalation delay that is unreadable or not positive (it would escalate at once).
     */
    public static function invalidDelay(CarbonInterval|string $value): self
    {
        return self::make('invalid_reminder', 422, ['value' => $value instanceof CarbonInterval ? $value->spec() : $value], 'invalid_escalation_delay');
    }
}
