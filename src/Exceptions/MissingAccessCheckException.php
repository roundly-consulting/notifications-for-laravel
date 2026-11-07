<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * A type relies on the default access check, but the subject has neither a policy nor the
 * configured Gate ability. Fail closed and loud: a developer error, caught in tests.
 */
final class MissingAccessCheckException extends NotificationsException
{
    public static function forType(string $type, string $subject, string $ability): self
    {
        return self::make('missing_access_check', 500, ['type' => $type, 'subject' => $subject, 'ability' => $ability]);
    }
}
