<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Inbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Events\InboxItemUnarchived;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Support\InboxQueries;

/**
 * Restores one of the recipient's items from the archive. It stays closed: the next event still
 * starts a new item.
 */
final readonly class UnarchiveInboxItemAction
{
    public function execute(Model $recipient, InboxItem|int|string $item): InboxItem
    {
        $item = InboxQueries::find($recipient, $item);

        if ($item->archived_at !== null) {
            $item->forceFill(['archived_at' => null])->save();

            Event::dispatch(new InboxItemUnarchived($item));
        }

        return $item;
    }
}
