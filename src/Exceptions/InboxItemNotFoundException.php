<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * An inbox item that does not exist in this recipient's inbox — the same answer for another
 * recipient's item, so the inbox is no existence oracle.
 */
final class InboxItemNotFoundException extends NotificationsException
{
    public static function notFound(): self
    {
        return self::make('inbox_item_not_found', 404);
    }
}
