<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The package's one notion of "now" and of a stored instant: UTC, `Y-m-d H:i:s`, whole seconds.
 * A host on `Europe/Bratislava` stores and compares the same instants as one on UTC.
 *
 * @internal
 */
final class Clock
{
    public const string FORMAT = 'Y-m-d H:i:s';

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfSecond();
    }

    public static function format(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->format(self::FORMAT);
    }

    /**
     * The instant in UTC, truncated to whole seconds — the precision every engine stores.
     */
    public static function utc(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->utc()->startOfSecond();
    }
}
