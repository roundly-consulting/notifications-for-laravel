<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

/**
 * One cap of the default mail throttle: at most `$max` mails per `$seconds`.
 */
final readonly class MailRateLimit
{
    public function __construct(
        public int $max,
        public int $seconds,
    ) {}
}
