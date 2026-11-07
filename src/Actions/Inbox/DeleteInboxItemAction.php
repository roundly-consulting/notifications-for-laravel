<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Inbox;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Support\InboxQueries;

/**
 * Deletes (soft) one of the recipient's items, closing its collapse key in the same write.
 */
final readonly class DeleteInboxItemAction
{
    public function execute(Model $recipient, InboxItem|int|string $item): void
    {
        $item = InboxQueries::find($recipient, $item);

        $item->open_key = null;
        $item->save();
        $item->delete();
    }
}
