<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Sending;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\DataTransferObjects\InboxWrite;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\InboxOutcome;
use RoundlyConsulting\Notifications\Events\InboxItemCreated;
use RoundlyConsulting\Notifications\Events\InboxItemUpdated;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Support\DeliveryPayloads;
use RoundlyConsulting\Notifications\Support\Keys;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\PreparedSend;
use RoundlyConsulting\Notifications\Support\Snapshots;
use RoundlyConsulting\Notifications\Support\Windows;

/**
 * The inbox step: the same type, subject, context and recipient within the window updates one
 * item (count++, latest actor and data, unread again) instead of adding another. The window is
 * fixed from the first event. A first insert runs under its own savepoint, so a lost race leaves
 * the surrounding transaction usable and the step retries.
 *
 * @internal
 */
final readonly class RecordInboxItemAction
{
    private const int ATTEMPTS = 3;

    public function execute(PreparedSend $send, Model $recipient): InboxWrite
    {
        $class = NotificationModels::inboxItem();
        $connection = (new $class)->getConnection();

        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction(fn (): InboxWrite => $this->step($send, $recipient));
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    private function step(PreparedSend $send, Model $recipient): InboxWrite
    {
        $class = NotificationModels::inboxItem();
        $window = $send->collapses ? Windows::of($send->type) : null;
        $key = Keys::collapse($recipient, $send->type->key(), $send->subjectIdentity(), $send->context?->key, $send->lane());
        $now = $send->at;

        if ($window !== null) {
            // Trashed rows still own their key: look them up too.
            $item = $class::query()->withTrashed()->where('open_key', $key)->lockForUpdate()->first();

            if ($item !== null && ! $item->trashed() && $item->archived_at === null
                && $item->collapse_until !== null && $now->lessThan($item->collapse_until)) {
                return $this->collapse($item, $send, $recipient);
            }

            if ($item !== null) {
                $item->open_key = null;
                $item->saveQuietly();
            }
        }

        $payload = $send->payload($recipient, Channel::Inbox);

        $item = new $class;
        $item->forceFill([
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
            'type' => $send->type->key(),
            'category' => $send->type->category(),
            'priority' => $send->type->priority(),
            'subject_type' => $send->subject?->getMorphClass(),
            'subject_id' => $send->subject?->getKey(),
            'context_type' => $send->context?->type,
            'context_id' => $send->context?->id,
            'context_key' => $send->context?->key,
            'context_label' => $send->context?->label,
            'actor_type' => $send->actor?->getMorphClass(),
            'actor_id' => $send->actor?->getKey(),
            'count' => 1,
            'data' => DeliveryPayloads::stored($send),
            'title' => Snapshots::title($send->type, $payload, $send->title),
            'url' => Snapshots::url($send->type, $payload),
            'open_key' => $window === null ? null : $key,
            'collapse_until' => $window === null ? null : $now->add($window),
            'reminder_id' => $send->reminderId,
            'first_event_at' => $now,
            'last_event_at' => $now,
        ]);
        $item->save();

        Event::dispatch(new InboxItemCreated($item));

        return new InboxWrite(InboxOutcome::Created, $item);
    }

    private function collapse(InboxItem $item, PreparedSend $send, Model $recipient): InboxWrite
    {
        $previous = $item->count;
        $count = $previous + 1;
        $payload = $send->payload($recipient, Channel::Inbox, $count, $item->first_event_at);

        $item->forceFill([
            'count' => $count,
            'last_event_at' => $send->at,
            'data' => DeliveryPayloads::stored($send),
            // A new event makes a read item unread again.
            'read_at' => null,
            'title' => Snapshots::title($send->type, $payload, $send->title),
            'url' => Snapshots::url($send->type, $payload),
            'reminder_id' => $send->reminderId ?? $item->reminder_id,
        ]);

        if ($send->actor !== null) {
            $item->forceFill(['actor_type' => $send->actor->getMorphClass(), 'actor_id' => ModelRefs::key($send->actor)]);
        }

        $item->save();

        Event::dispatch(new InboxItemUpdated($item, $previous));

        return new InboxWrite(InboxOutcome::Collapsed, $item);
    }
}
