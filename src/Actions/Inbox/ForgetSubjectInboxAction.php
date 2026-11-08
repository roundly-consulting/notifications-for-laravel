<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Inbox;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\NotificationModels;

/**
 * Archives or deletes (soft) every inbox item about a deleted subject, closing each collapse key —
 * `notifications.inbox.on_subject_deleted`. Run by the subject traits on delete; returns how many
 * items it touched.
 *
 * @internal
 */
final readonly class ForgetSubjectInboxAction
{
    /**
     * @param  string  $mode  `archive`, else delete
     */
    public function execute(Model $subject, string $mode): int
    {
        $items = NotificationModels::inboxItem()::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->get();

        foreach ($items as $item) {
            $item->open_key = null;

            if ($mode === 'archive') {
                $item->archived_at ??= Clock::now();
                $item->save();
            } else {
                $item->save();
                $item->delete();
            }
        }

        return $items->count();
    }
}
