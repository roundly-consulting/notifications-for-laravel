<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Inbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Actions\Reminders\AcknowledgeAction;
use RoundlyConsulting\Notifications\Events\InboxItemsRead;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\InboxQueries;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\ReadAcknowledgements;

/**
 * Marks one of the recipient's items read. Reading an item that came from a reminder declared
 * `acknowledgedOnRead()` acknowledges it.
 */
final readonly class MarkInboxItemReadAction
{
    public function __construct(
        private AcknowledgeAction $acknowledge,
    ) {}

    public function execute(Model $recipient, InboxItem|int|string $item): InboxItem
    {
        $item = InboxQueries::find($recipient, $item);

        if ($item->read_at === null) {
            $item->read_at = Clock::now();
            $item->save();

            Event::dispatch(new InboxItemsRead($recipient, [ModelRefs::key($item)]));
            ReadAcknowledgements::process([$item], $recipient, $this->acknowledge);
        }

        return $item;
    }
}
