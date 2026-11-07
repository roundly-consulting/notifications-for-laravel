<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * A type definition that cannot be registered: a bad key or category, a digestible type that is
 * not low priority, a window that is too long, or a fluent type without a title.
 */
final class InvalidNotificationTypeException extends NotificationsException
{
    public static function invalidKey(string $key): self
    {
        return self::make('invalid_type', 422, ['type' => $key], 'invalid_type_key');
    }

    public static function invalidCategory(string $key, string $category): self
    {
        return self::make('invalid_type', 422, ['type' => $key, 'category' => $category], 'invalid_type_category');
    }

    public static function digestibleNotLow(string $key): self
    {
        return self::make('invalid_type', 422, ['type' => $key], 'invalid_type_digestible');
    }

    public static function invalidWindow(string $key): self
    {
        return self::make('invalid_type', 422, ['type' => $key], 'invalid_type_window');
    }

    public static function noChannels(string $key): self
    {
        return self::make('invalid_type', 422, ['type' => $key], 'invalid_type_channels');
    }

    public static function invalidSubject(string $key): self
    {
        return self::make('invalid_type', 422, ['type' => $key], 'invalid_type_subject');
    }

    public static function missingTitle(string $key): self
    {
        return self::make('invalid_type', 422, ['type' => $key], 'invalid_type_title');
    }
}
