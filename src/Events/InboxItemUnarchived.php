<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Notifications\Models\InboxItem;

/**
 * An inbox item was restored from the archive.
 */
final readonly class InboxItemUnarchived implements ShouldDispatchAfterCommit
{
    public function __construct(
        public InboxItem $item,
    ) {}
}
