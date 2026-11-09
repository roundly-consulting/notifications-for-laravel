<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * An unsubscribe link that was tampered with, made with another app key, or has expired.
 */
final class InvalidUnsubscribeTokenException extends NotificationsException
{
    public static function invalid(): self
    {
        return self::make('invalid_unsubscribe_token', 403);
    }
}
