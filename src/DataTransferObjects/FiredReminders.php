<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

/**
 * Reminders and escalations one run fired.
 */
final readonly class FiredReminders
{
    public function __construct(
        public int $reminders = 0,
        public int $escalations = 0,
    ) {}
}
