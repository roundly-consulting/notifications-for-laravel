<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * Notification data that is not plain JSON, too large, or a context that looks like a class name.
 */
final class InvalidNotificationDataException extends NotificationsException
{
    public static function notJson(string $path): self
    {
        return self::make('invalid_data', 422, ['path' => $path]);
    }

    public static function tooLarge(int $bytes, int $max): self
    {
        return self::make('invalid_data', 422, ['bytes' => $bytes, 'max' => $max], 'data_too_large');
    }

    public static function invalidContext(): self
    {
        return self::make('invalid_data', 422, [], 'invalid_context');
    }
}
