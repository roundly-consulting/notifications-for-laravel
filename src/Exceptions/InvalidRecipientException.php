<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * A recipient that is not an Eloquent model.
 */
final class InvalidRecipientException extends NotificationsException
{
    public static function notAModel(string $given): self
    {
        return self::make('invalid_recipient', 422, ['given' => $given]);
    }
}
