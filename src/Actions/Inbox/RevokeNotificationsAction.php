<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Inbox;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\ReminderRows;

/**
 * When a recipient loses access to a subject (left the team, the record moved): deletes their
 * inbox items for it and cancels their pending, held and digest mail. Without a recipient, does
 * so for everyone and cancels the subject's pending reminders and escalations too.
 */
final readonly class RevokeNotificationsAction
{
    public function execute(Model $subject, ?Model $recipient = null): int
    {
        return (int) NotificationModels::inboxItem()::query()->getConnection()->transaction(static function () use ($subject, $recipient): int {
            $items = NotificationModels::inboxItem()::query()
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->when($recipient !== null, static fn ($query) => $query
                    ->where('recipient_type', $recipient?->getMorphClass())
                    ->where('recipient_id', $recipient?->getKey()));

            $removed = 0;

            foreach ($items->get() as $item) {
                $item->open_key = null;
                $item->save();
                $item->delete();
                $removed++;
            }

            $removed += NotificationModels::delivery()::query()
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Held->value, DeliveryStatus::Digest->value])
                ->whereNull('digest_run_id')
                ->when($recipient !== null, static fn ($query) => $query
                    ->where('recipient_type', $recipient?->getMorphClass())
                    ->where('recipient_id', $recipient?->getKey()))
                ->update(['status' => DeliveryStatus::Cancelled->value, 'cancel_reason' => CancelReason::Revoked->value, 'open_key' => null]);

            if ($recipient === null) {
                $removed += ReminderRows::cancelPending($subject, null, CancelReason::Revoked);
            }

            return $removed;
        });
    }
}
