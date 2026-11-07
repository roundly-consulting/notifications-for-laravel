<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * An attempt to mute a type that cannot be muted.
 */
final class MandatoryNotificationException extends NotificationsException
{
    public static function forType(string $type): self
    {
        return self::make('mandatory_notification', 422, ['type' => $type]);
    }
}
