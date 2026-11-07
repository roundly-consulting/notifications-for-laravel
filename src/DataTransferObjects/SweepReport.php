<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

/**
 * What one sweep did: due deliveries dispatched, digest runs started, reminders and escalations
 * fired, stale claims expired.
 */
final readonly class SweepReport
{
    public function __construct(
        public int $deliveries = 0,
        public int $digests = 0,
        public int $reminders = 0,
        public int $escalations = 0,
        public int $expired = 0,
    ) {}
}
