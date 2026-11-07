<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Throwable;

/**
 * Durations: strict ISO-8601 for config and stored offsets, lenient (anything
 * `CarbonInterval::make()` reads, `'10 minutes'` included) for type and builder input.
 *
 * @internal
 */
final class Durations
{
    private const string ISO = '/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/';

    /**
     * A strict ISO-8601 duration, or null when `$value` is not one.
     */
    public static function iso(string $value): ?CarbonInterval
    {
        if (preg_match(self::ISO, $value) !== 1) {
            return null;
        }

        try {
            return new CarbonInterval($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A lenient positive duration, or null when unreadable or not positive.
     */
    public static function lenient(CarbonInterval|string $value): ?CarbonInterval
    {
        if ($value instanceof CarbonInterval) {
            return self::seconds($value) > 0 ? $value : null;
        }

        try {
            $interval = self::iso($value) ?? CarbonInterval::make($value);
        } catch (Throwable) {
            return null;
        }

        return $interval instanceof CarbonInterval && self::seconds($interval) > 0 ? $interval : null;
    }

    /**
     * The interval's length in seconds, measured from a fixed UTC instant so months and years
     * have a definite length.
     */
    public static function seconds(CarbonInterval $interval): int
    {
        $from = CarbonImmutable::createFromTimestampUTC(946_684_800);

        return (int) $from->add($interval)->getTimestamp() - (int) $from->getTimestamp();
    }

    /**
     * The interval written back as a canonical ISO-8601 string (`P1DT2H`).
     */
    public static function toIso(CarbonInterval $interval): string
    {
        $date = ($interval->y > 0 ? $interval->y.'Y' : '')
            .($interval->m > 0 ? $interval->m.'M' : '')
            .($interval->d > 0 ? $interval->d.'D' : '');
        $time = ($interval->h > 0 ? $interval->h.'H' : '')
            .($interval->i > 0 ? $interval->i.'M' : '')
            .($interval->s > 0 ? $interval->s.'S' : '');

        $iso = 'P'.$date.($time !== '' ? 'T'.$time : '');

        return $iso === 'P' ? 'PT0S' : $iso;
    }
}
