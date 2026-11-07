<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Inbox;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Support\InboxQueries;

/**
 * Marks one of the recipient's items unread.
 */
final readonly class MarkInboxItemUnreadAction
{
    public function execute(Model $recipient, InboxItem|int|string $item): InboxItem
    {
        $item = InboxQueries::find($recipient, $item);

        if ($item->read_at !== null) {
            $item->read_at = null;
            $item->save();
        }

        return $item;
    }
}
