<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

/**
 * Rows one prune deleted, per table.
 */
final readonly class PruneReport
{
    public function __construct(
        public int $inboxItems = 0,
        public int $deliveries = 0,
        public int $digestRuns = 0,
        public int $reminders = 0,
        public int $receipts = 0,
    ) {}

    public function total(): int
    {
        return $this->inboxItems + $this->deliveries + $this->digestRuns + $this->reminders + $this->receipts;
    }
}
