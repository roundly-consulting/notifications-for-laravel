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
     * An escalation delay that is unreadable or not positive (it would escalate at once).
     */
    public static function invalidDelay(CarbonInterval|string $value): self
    {
        return self::make('invalid_reminder', 422, ['value' => $value instanceof CarbonInterval ? $value->spec() : $value], 'invalid_escalation_delay');
    }
}
