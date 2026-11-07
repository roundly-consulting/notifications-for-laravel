<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

use Carbon\CarbonImmutable;

/**
 * The reminder a send came from: its rule key, signed ISO offset and due instant (UTC).
 */
final readonly class ReminderReference
{
    public function __construct(
        public int|string $id,
        public ?string $rule,
        public ?string $offset,
        public CarbonImmutable $dueAt,
        public int $level = 0,
    ) {}
}
