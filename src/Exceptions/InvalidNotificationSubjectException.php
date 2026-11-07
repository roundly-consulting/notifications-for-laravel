<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

/**
 * A subject of the wrong class, or a subject missing where one is required.
 */
final class InvalidNotificationSubjectException extends NotificationsException
{
    public static function wrongClass(string $type, string $expected, string $given): self
    {
        return self::make('invalid_subject', 422, ['type' => $type, 'expected' => $expected, 'given' => $given]);
    }

    public static function unexpected(string $type, string $given): self
    {
        return self::make('invalid_subject', 422, ['type' => $type, 'given' => $given], 'unexpected_subject');
    }

    public static function required(string $type): self
    {
        return self::make('invalid_subject', 422, ['type' => $type], 'subject_required');
    }
}
