<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Sending;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Contracts\ResolvesTimezone;
use RoundlyConsulting\Notifications\DataTransferObjects\MailWrite;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\MailOutcome;
use RoundlyConsulting\Notifications\Enums\MailStrategy;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Events\NotificationMailHeld;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Support\DeliveryPayloads;
use RoundlyConsulting\Notifications\Support\DigestSchedule;
use RoundlyConsulting\Notifications\Support\Keys;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\PreparedSend;
use RoundlyConsulting\Notifications\Support\QuietHoursClock;
use RoundlyConsulting\Notifications\Support\Windows;

/**
 * The mail step. Within the window, events fold into one delivery: an unsent one absorbs them
 * (`Folded`), a sent one only counts them (`Deduplicated`). Otherwise a new delivery is pending
 * (now, or after the window for a trailing type), held until quiet hours end (unless urgent), or
 * parked for the user's digest (low priority + digestible).
 *
 * @internal
 */
final readonly class QueueMailDeliveryAction
{
    private const int ATTEMPTS = 3;

    public function execute(PreparedSend $send, Model $recipient, ?InboxItem $item): MailWrite
    {
        $connection = (new (NotificationModels::delivery()))->getConnection();

        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction(fn (): MailWrite => $this->step($send, $recipient, $item));
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    private function step(PreparedSend $send, Model $recipient, ?InboxItem $item): MailWrite
    {
        $class = NotificationModels::delivery();
        $type = $send->type;
        $window = Windows::of($type);
        $key = Keys::dedupe($recipient, $type->key(), $send->subjectIdentity(), $send->context?->key, $send->lane());
        $now = $send->at;

        if ($window !== null) {
            $delivery = $class::query()->withTrashed()->where('open_key', $key)->lockForUpdate()->first();

            if ($delivery !== null && ! $delivery->trashed()) {
                if ($delivery->status->isOpen()) {
                    $this->fold($delivery, $send, $item, full: true);

                    return new MailWrite(MailOutcome::Folded, $delivery);
                }

                if (in_array($delivery->status, [DeliveryStatus::Sending, DeliveryStatus::Sent], true)
                    && $delivery->window_ends_at !== null && $now->lessThan($delivery->window_ends_at)) {
                    $this->fold($delivery, $send, $item, full: false);

                    return new MailWrite(MailOutcome::Deduplicated, $delivery);
                }
            }

            if ($delivery !== null) {
                $delivery->open_key = null;
                $delivery->saveQuietly();
            }
        }

        $attributes = [
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
            'type' => $type->key(),
            'channel' => 'mail',
            'subject_type' => $send->subject?->getMorphClass(),
            'subject_id' => $send->subject?->getKey(),
            'inbox_item_id' => $item?->getKey(),
            'open_key' => $window === null ? null : $key,
            'window_ends_at' => $window === null ? null : $now->add($window),
            'events_count' => 1,
            'first_event_at' => $now,
            'last_event_at' => $now,
            'actor_type' => $send->actor?->getMorphClass(),
            'actor_id' => $send->actor?->getKey(),
            'context_key' => $send->context?->key,
            'context_label' => $send->context?->label,
            'data' => DeliveryPayloads::stored($send),
            'source' => NotificationDelivery::SOURCE_TYPE,
            'attempts' => 0,
        ];

        $timezone = app(ResolvesTimezone::class)->timezone($recipient);
        $quiet = Preferences::quietHours($recipient);
        $urgent = $type->priority() === Priority::Urgent;
        $digest = $type->priority() === Priority::Low && $type->digestible() ? Preferences::digest($recipient) : null;

        if ($digest !== null && $digest->frequency !== DigestFrequency::Off) {
            [$due, $period] = DigestSchedule::next($now, $timezone, $digest->frequency, $digest->time ?? NotificationsConfig::digestTime(), $quiet);

            $delivery = $this->insert($attributes + [
                'status' => DeliveryStatus::Digest,
                'send_after' => $due,
                'digest_frequency' => $digest->frequency,
                'digest_period' => $period,
            ]);

            return new MailWrite(MailOutcome::Digest, $delivery);
        }

        $sendAfter = $window !== null && ! $urgent && Windows::strategy($type) === MailStrategy::Trailing
            ? $now->add($window)
            : $now;

        if (! $urgent && $quiet !== null && QuietHoursClock::isQuiet($sendAfter, $timezone, $quiet)) {
            $until = QuietHoursClock::end($sendAfter, $timezone, $quiet);
            $delivery = $this->insert($attributes + ['status' => DeliveryStatus::Held, 'send_after' => $until]);

            Event::dispatch(new NotificationMailHeld($delivery, $until));

            return new MailWrite(MailOutcome::Held, $delivery);
        }

        $delivery = $this->insert($attributes + ['status' => DeliveryStatus::Pending, 'send_after' => $sendAfter]);

        return new MailWrite(MailOutcome::Pending, $delivery);
    }

    private function fold(NotificationDelivery $delivery, PreparedSend $send, ?InboxItem $item, bool $full): void
    {
        $delivery->events_count++;
        $delivery->last_event_at = $send->at;

        if ($full) {
            $delivery->data = DeliveryPayloads::stored($send);
            $delivery->inbox_item_id = $item === null ? $delivery->inbox_item_id : ModelRefs::key($item);

            if ($send->actor !== null) {
                $delivery->forceFill(['actor_type' => $send->actor->getMorphClass(), 'actor_id' => $send->actor->getKey()]);
            }
        }

        $delivery->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insert(array $attributes): NotificationDelivery
    {
        $class = NotificationModels::delivery();
        $delivery = new $class;
        $delivery->forceFill($attributes);
        $delivery->save();

        return $delivery;
    }
}
