<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Notifications\Models\InboxItem;

/**
 * An event collapsed into an existing inbox item (count raised, latest actor and data).
 */
final readonly class InboxItemUpdated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public InboxItem $item,
        public int $previousCount,
    ) {}
}
