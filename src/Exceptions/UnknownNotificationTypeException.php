<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * A type key nothing registered.
 */
final class UnknownNotificationTypeException extends NotificationsException
{
    public static function forKey(string $key): self
    {
        return self::make('unknown_type', 422, ['type' => $key]);
    }
}
