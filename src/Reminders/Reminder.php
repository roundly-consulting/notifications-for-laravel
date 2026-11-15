<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Reminders;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use RoundlyConsulting\Notifications\Exceptions\InvalidReminderRuleException;

/**
 * Reminder rules: relative to a date attribute of the subject (`before` / `after`), at an
 * absolute instant (`at`, for ad-hoc schedules), or repeating (`every`) until acknowledged.
 *
 * ```php
 * Reminder::before('expires_at', 'P60D', 'P30D', 'P7D')->to(Recipients::owner());
 * Reminder::every('7 days')->from('published_at')->acknowledgedOnRead();
 * ```
 */
final class Reminder
{
    public static function before(string $attribute, CarbonInterval|string ...$offsets): ReminderRule
    {
        return new ReminderRule('before', $attribute, array_values($offsets));
    }

    public static function after(string $attribute, CarbonInterval|string ...$offsets): ReminderRule
    {
        return new ReminderRule('after', $attribute, array_values($offsets));
    }

    public static function at(CarbonInterface $when): ReminderRule
    {
        return new ReminderRule('at', null, [], $when);
    }

    /**
     * A repeating reminder: one chain per recipient, a reminder every `$interval` until the
     * recipient acknowledges, the condition fails, the reminders are cancelled, or `times()` /
     * `until()` is reached. Anchored to `from($attribute)`, or to when it was scheduled.
     *
     * @throws InvalidReminderRuleException when the interval is unreadable or not positive
     */
    public static function every(CarbonInterval|string $interval): ReminderRule
    {
        return ReminderRule::repeating($interval);
    }
}
