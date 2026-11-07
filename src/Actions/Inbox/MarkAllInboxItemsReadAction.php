<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Inbox;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Actions\Reminders\AcknowledgeAction;
use RoundlyConsulting\Notifications\Events\InboxItemsRead;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\Contexts;
use RoundlyConsulting\Notifications\Support\InboxQueries;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\ReadAcknowledgements;

/**
 * Marks every unread, not archived item of the recipient read — optionally only in one context
 * (or only items without one), one category, one type, or with activity since an instant — in one
 * UPDATE. Returns how many.
 */
final readonly class MarkAllInboxItemsReadAction
{
    public function __construct(
        private AcknowledgeAction $acknowledge,
    ) {}

    public function execute(
        Model $recipient,
        Model|string|null $in = null,
        ?string $category = null,
        ?string $type = null,
        bool $withoutContext = false,
        ?CarbonInterface $since = null,
    ): int {
        $query = InboxQueries::for($recipient)->whereNull('read_at')->whereNull('archived_at');

        if ($in !== null) {
            $query->where('context_key', Contexts::keyOf($in));
        } elseif ($withoutContext) {
            $query->whereNull('context_key');
        }

        if ($category !== null) {
            $query->where('category', $category);
        }

        if ($type !== null) {
            $query->where('type', $type);
        }

        if ($since !== null) {
            $query->where('last_event_at', '>=', Clock::format($since));
        }

        $items = (clone $query)->get(['id', 'reminder_id', 'type', 'subject_type', 'subject_id']);

        if ($items->isEmpty()) {
            return 0;
        }

        $ids = array_values($items->map(static fn (InboxItem $item): int|string => ModelRefs::key($item))->all());

        $marked = InboxQueries::for($recipient)->whereKey($ids)->whereNull('read_at')
            ->update(['read_at' => Clock::now()->format(Clock::FORMAT)]);

        Event::dispatch(new InboxItemsRead($recipient, $ids));
        ReadAcknowledgements::process($items->all(), $recipient, $this->acknowledge);

        return $marked;
    }
}
