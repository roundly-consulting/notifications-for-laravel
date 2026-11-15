<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RoundlyConsulting\Notifications\Exceptions\InvalidReminderRuleException;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;

/**
 * The up-front checks of a snooze, shared by the handle (so `Notifications::fake()` refuses what
 * production refuses) and the action.
 *
 * @internal
 */
final class ReminderSnoozes
{
    /**
     * The type's repeating rules, once the snooze is known to apply.
     *
     * @return non-empty-list<ReminderRule>
     *
     * @throws InvalidReminderRuleException when `$until` is not in the future, or the type has no
     *                                      repeating rule
     */
    public static function rules(NotificationType $type, CarbonInterface $until, CarbonImmutable $now): array
    {
        if (! Clock::utc($until)->greaterThan($now)) {
            throw InvalidReminderRuleException::invalidSnooze($type->key(), sprintf('until (%s) is not in the future', Clock::format($until)));
        }

        $rules = array_values(array_filter($type->reminders(), static fn (ReminderRule $rule): bool => $rule->repeats()));

        if ($rules === []) {
            throw InvalidReminderRuleException::invalidSnooze($type->key(), 'it has no repeating rule (Reminder::every())');
        }

        return $rules;
    }
}
