<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Notifications\DataTransferObjects\QuietHours;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;

/**
 * The next digest slot and its period, in the user's timezone: daily `Y-m-d`, weekly
 * `GGGG-[W]WW`. A slot inside quiet hours moves to their end (the period stays).
 *
 * @internal
 */
final class DigestSchedule
{
    /**
     * @return array{0: CarbonImmutable, 1: string} the due instant (UTC) and the period
     */
    public static function next(CarbonImmutable $now, string $timezone, DigestFrequency $frequency, string $time, ?QuietHours $quiet): array
    {
        $local = $now->setTimezone($timezone);
        $slot = $local->setTimeFromTimeString($time.':00');

        if ($frequency === DigestFrequency::Weekly) {
            $day = NotificationsConfig::digestWeeklyDay();

            if (strtolower($slot->englishDayOfWeek) !== $day || $slot <= $local) {
                $slot = $local->next(ucfirst($day))->setTimeFromTimeString($time.':00');
            }

            $period = $slot->isoFormat('GGGG-[W]WW');
        } else {
            if ($slot <= $local) {
                // Re-set the time after moving the day: a DST change keeps the local slot.
                $slot = $local->addDay()->setTimeFromTimeString($time.':00');
            }

            $period = $slot->format('Y-m-d');
        }

        $due = QuietHoursClock::after(Clock::utc($slot), $timezone, $quiet);

        return [$due, $period];
    }

    /**
     * The one-off flush period used when a user switches digests off: never produced by a daily
     * or weekly slot, so it cannot collide with a sent run.
     */
    public static function flushPeriod(CarbonImmutable $now): string
    {
        return 'off-'.$now->format('YmdHi');
    }
}
