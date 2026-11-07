<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * A timezone that is not an IANA identifier.
 */
final class InvalidTimezoneException extends NotificationsException
{
    public static function forValue(string $value): self
    {
        return self::make('invalid_timezone', 422, ['value' => $value]);
    }
}
