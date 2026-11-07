<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Inbox items were marked read (one, or many at once with markAllRead()).
 */
final readonly class InboxItemsRead implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<int|string>  $ids
     */
    public function __construct(
        public Model $recipient,
        public array $ids,
    ) {}
}
