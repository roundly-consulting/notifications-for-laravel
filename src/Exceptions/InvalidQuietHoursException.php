<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * Quiet hours that are not two different local `HH:MM` times.
 */
final class InvalidQuietHoursException extends NotificationsException
{
    public static function sameStartAndEnd(): self
    {
        return self::make('invalid_quiet_hours', 422);
    }

    public static function invalidTime(string $value): self
    {
        return self::make('invalid_quiet_hours', 422, ['value' => $value], 'invalid_time');
    }
}
