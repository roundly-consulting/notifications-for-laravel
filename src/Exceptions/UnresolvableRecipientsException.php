<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * A group the group resolver cannot read members from.
 */
final class UnresolvableRecipientsException extends NotificationsException
{
    public static function forGroup(string $group): self
    {
        return self::make('unresolvable_recipients', 422, ['group' => $group]);
    }
}
