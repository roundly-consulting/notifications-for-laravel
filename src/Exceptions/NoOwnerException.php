<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * Recipients::owner() on a subject that names no owner.
 */
final class NoOwnerException extends NotificationsException
{
    public static function forSubject(?string $subject): self
    {
        return $subject === null
            ? self::make('no_owner', 422, [], 'no_owner_subject')
            : self::make('no_owner', 422, ['subject' => $subject]);
    }
}
