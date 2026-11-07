<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Inbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Events\InboxItemArchived;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\InboxQueries;

/**
 * Archives one of the recipient's items. It stops collapsing: the next event starts a new item.
 */
final readonly class ArchiveInboxItemAction
{
    public function execute(Model $recipient, InboxItem|int|string $item): InboxItem
    {
        $item = InboxQueries::find($recipient, $item);

        if ($item->archived_at === null) {
            $item->forceFill(['archived_at' => Clock::now(), 'open_key' => null])->save();

            Event::dispatch(new InboxItemArchived($item));
        }

        return $item;
    }
}
