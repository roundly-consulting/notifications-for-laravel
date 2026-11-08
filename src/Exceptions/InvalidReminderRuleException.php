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
    /**
     * An escalation level whose `type:` is not registered, or cannot take the escalated subject.
     */
    public static function escalationType(string $type, string $escalation): self
    {
        return self::make('invalid_reminder', 422, ['type' => $type, 'escalation' => $escalation], 'invalid_escalation_type');
    }

    public static function invalidDelay(CarbonInterval|string $value): self
    {
        return self::make('invalid_reminder', 422, ['value' => $value instanceof CarbonInterval ? $value->spec() : $value], 'invalid_escalation_delay');
    }
}
