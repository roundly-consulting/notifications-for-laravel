<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Reminders;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;

/**
 * Reminder rules: relative to a date attribute of the subject (`before` / `after`), or at an
 * absolute instant (`at`, for ad-hoc schedules).
 *
 * ```php
 * Reminder::before('expires_at', 'P60D', 'P30D', 'P7D')->to(Recipients::owner());
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
}
