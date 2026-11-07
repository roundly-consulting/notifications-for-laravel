<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * An interop map entry that points at an unknown type, or maps a time-critical notification (a
 * one-time code, a magic link, a password reset) to a type that could hold, fold or digest it.
 */
final class InvalidInteropMappingException extends NotificationsException
{
    public static function unknownType(string $notification, string $type): self
    {
        return self::make('invalid_interop_mapping', 500, ['notification' => $notification, 'type' => $type]);
    }

    public static function timeCritical(string $notification, string $type): self
    {
        return self::make('invalid_interop_mapping', 500, ['notification' => $notification, 'type' => $type], 'interop_time_critical');
    }
}
