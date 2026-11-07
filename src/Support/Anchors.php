<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Reads a reminder anchor (a date attribute) through the host's cast, as a UTC instant in whole
 * seconds — the precision every engine stores, so a sub-second difference never counts as a move.
 *
 * @internal
 */
final class Anchors
{
    public static function read(Model $subject, string $attribute): ?CarbonImmutable
    {
        $value = $subject->getAttribute($attribute);

        if ($value instanceof DateTimeInterface) {
            return Clock::utc($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $timezone = config('app.timezone');

            return Clock::utc(CarbonImmutable::parse($value, is_string($timezone) && $timezone !== '' ? $timezone : 'UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    public static function same(?CarbonImmutable $a, ?CarbonImmutable $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a->getTimestamp() === $b->getTimestamp();
    }
}
