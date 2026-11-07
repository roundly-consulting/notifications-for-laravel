<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

/**
 * A daily quiet window in the user's local time; it may cross midnight (`22:00`–`07:00`).
 */
final readonly class QuietHours
{
    public function __construct(
        public string $start,
        public string $end,
    ) {}
}
