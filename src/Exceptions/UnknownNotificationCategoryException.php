<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * A category no registered type uses — a typo would otherwise mute nothing.
 */
final class UnknownNotificationCategoryException extends NotificationsException
{
    public static function forCategory(string $category): self
    {
        return self::make('unknown_notification_category', 422, ['category' => $category]);
    }
}
