<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Notifications\Models\InboxItem;

/**
 * A new inbox item (broadcast it with InboxItemResource on your private channel).
 */
final readonly class InboxItemCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public InboxItem $item,
    ) {}
}
