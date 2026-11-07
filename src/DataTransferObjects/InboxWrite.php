<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

use RoundlyConsulting\Notifications\Enums\InboxOutcome;
use RoundlyConsulting\Notifications\Models\InboxItem;

/**
 * What the inbox step did, and the item it wrote.
 */
final readonly class InboxWrite
{
    public function __construct(
        public InboxOutcome $outcome,
        public InboxItem $item,
    ) {}
}
