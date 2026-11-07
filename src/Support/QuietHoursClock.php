<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Notifications\DataTransferObjects\QuietHours;

/**
 * Quiet-hours math in the user's timezone. A window may cross midnight. A local end that falls
 * in a DST gap moves forward (Carbon's rule); an ambiguous one takes the first occurrence.
 *
 * @internal
 */
final class QuietHoursClock
{
    public static function isQuiet(CarbonImmutable $now, string $timezone, QuietHours $quiet): bool
    {
        $time = $now->setTimezone($timezone)->format('H:i');

        return $quiet->start < $quiet->end
            ? $time >= $quiet->start && $time < $quiet->end
            : $time >= $quiet->start || $time < $quiet->end;
    }

    /**
     * When the quiet window that contains `$now` ends (UTC).
     */
    public static function end(CarbonImmutable $now, string $timezone, QuietHours $quiet): CarbonImmutable
    {
        $local = $now->setTimezone($timezone);
        $time = $local->format('H:i');

        $end = $local->setTimeFromTimeString($quiet->end.':00');

        if ($time >= $quiet->end) {
            $end = $local->addDay()->setTimeFromTimeString($quiet->end.':00');
        }

        return Clock::utc($end);
    }

    /**
     * `$at` itself, or the end of the quiet window it falls in.
     */
    public static function after(CarbonImmutable $at, string $timezone, ?QuietHours $quiet): CarbonImmutable
    {
        if ($quiet === null || ! self::isQuiet($at, $timezone, $quiet)) {
            return $at;
        }

        return self::end($at, $timezone, $quiet);
    }
}
