<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * Another write holds the user's preference lock longer than the wait allows. Retryable: the API
 * answers 423 with a message instead of a 500.
 */
final class PreferencesLockedException extends NotificationsException
{
    public static function busy(): self
    {
        return self::make('preferences_locked', 423);
    }
}
