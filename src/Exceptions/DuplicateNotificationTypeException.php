<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * Two registrations claimed one type key.
 */
final class DuplicateNotificationTypeException extends NotificationsException
{
    public static function forKey(string $key, string $first, string $second): self
    {
        return self::make('duplicate_type', 422, ['type' => $key, 'first' => $first, 'second' => $second]);
    }
}
