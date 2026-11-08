<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Interop;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RoundlyConsulting\Notifications\Actions\Sending\RecordInboxItemAction;
use RoundlyConsulting\Notifications\Contracts\ResolvesTimezone;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Events\NotificationInteropFailed;
use RoundlyConsulting\Notifications\Events\NotificationMailHeld;
use RoundlyConsulting\Notifications\Mail\DigestMailNotification;
use RoundlyConsulting\Notifications\Mail\TypedMailNotification;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Support\Access;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\Contexts;
use RoundlyConsulting\Notifications\Support\DeliveryPayloads;
use RoundlyConsulting\Notifications\Support\InteropGate;
use RoundlyConsulting\Notifications\Support\InteropRules;
use RoundlyConsulting\Notifications\Support\Keys;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\Payloads;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\PreparedSend;
use RoundlyConsulting\Notifications\Support\QuietHoursClock;
use RoundlyConsulting\Notifications\Support\Subjects;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Support\Windows;
use RoundlyConsulting\Notifications\Traits\ReceivesNotifications;
use Throwable;

/**
 * Applies the package's rules to another package's notification, per notifiable and channel, at
 * delivery time (Laravel's NotificationSending). Returns false to cancel the channel, null to let
 * it through. Suppression always applies; everything else only to recipients (models using
 * ReceivesNotifications or listed in `notifications.interop.notifiables`), on `mail` and
 * `database`. Any error is reported and the notification goes out untouched — an interop bug must
 * never stop a password reset.
 *
 * @internal
 */
final readonly class ApplyInteropRulesAction
{
    /** How long an unconfirmed `sending` claim absorbs later mails. */
    private const int CLAIM_GRACE_SECONDS = 60;

    public function __construct(
        private InteropRules $rules,
        private TypeRegistry $types,
        private RecordInboxItemAction $recordInboxItem,
    ) {}

    public function execute(object $notifiable, Notification $notification, string $channel): ?bool
    {
        if ($notification instanceof TypedMailNotification || $notification instanceof DigestMailNotification || InteropGate::isReleasing()) {
            return null;
        }

        if ($this->rules->suppresses($notification, $channel)) {
            return false;
        }

        try {
            return $this->apply($notifiable, $notification, $channel);
        } catch (Throwable $exception) {
            report($exception);
            Event::dispatch(new NotificationInteropFailed(class_basename($notification), $channel, class_basename($exception)));

            return null;
        }
    }

    private function apply(object $notifiable, Notification $notification, string $channel): ?bool
    {
        $key = $this->rules->typeFor($notification);

        if ($key === null || ! $notifiable instanceof Model || ! self::isRecipient($notifiable) || ! in_array($channel, ['mail', 'database'], true)) {
            return null;
        }

        $this->rules->validate($this->types);
        $type = $this->types->find($key);

        // A mapping of an ancestor class matches a password reset or a sign-in code too: those
        // pass untouched unless the type cannot hold, fold or digest them (validate() checks only
        // the mapped class itself).
        if (InteropRules::timeCritical($notification::class) && ! InteropRules::fitsTimeCritical($type)) {
            return null;
        }

        $subject = Subjects::fresh($type, $type->subjectFrom($notification));
        $checkable = $type->subject() === null || $subject !== null;

        if ($subject !== null && ! Access::allows($type, $notifiable, $subject, true)) {
            return false;
        }

        return $channel === 'database'
            ? $this->database($notifiable, $notification, $type, $subject, $checkable)
            : $this->mail($notifiable, $notification, $type, $subject, $checkable);
    }

    private function database(Model $recipient, Notification $notification, NotificationType $type, ?Model $subject, bool $checkable): ?bool
    {
        $mode = NotificationsConfig::interopDatabase();

        if ($mode === 'keep') {
            return null;
        }

        if (! Preferences::enabled($type, Channel::Inbox, $recipient)) {
            return false;
        }

        $data = self::data($notification, $recipient);
        $title = is_string($data['title'] ?? null) ? $data['title'] : null;

        $send = new PreparedSend(
            type: $type,
            subject: $subject,
            subjectGiven: $subject !== null,
            data: Payloads::assertJson($data + [DeliveryPayloads::META => ['source' => 'interop']]),
            actor: null,
            context: Contexts::resolve($type->context($subject)),
            idempotencyKey: null,
            escalationLevel: 0,
            reminderId: null,
            reminder: null,
            at: Clock::now(),
            title: $title,
            // As for mail: notifications about an unknown subject are not the same event — never
            // collapse them into one item.
            collapses: $checkable,
        );

        $this->recordInboxItem->execute($send, $recipient);

        return $mode === 'redirect' ? false : null;
    }

    private function mail(Model $recipient, Notification $notification, NotificationType $type, ?Model $subject, bool $checkable): ?bool
    {
        if (! Preferences::enabled($type, Channel::Mail, $recipient)) {
            return false;
        }

        $window = $checkable ? Windows::of($type) : null;
        $context = Contexts::resolve($type->context($subject));
        $openKey = Keys::dedupe($recipient, $type->key(), $subject === null ? null : ModelRefs::identity($subject), $context?->key, Keys::ORDINARY_LANE);
        $now = Clock::now();

        $timezone = app(ResolvesTimezone::class)->timezone($recipient);
        $quiet = Preferences::quietHours($recipient);
        $until = NotificationsConfig::interopHold() && $type->priority() !== Priority::Urgent
            && $quiet !== null && QuietHoursClock::isQuiet($now, $timezone, $quiet)
            ? QuietHoursClock::end($now, $timezone, $quiet)
            : null;

        $hold = $until !== null;
        $payload = null;

        if ($hold) {
            try {
                $payload = Crypt::encryptString(serialize(clone $notification));
            } catch (Throwable) {
                // Not serializable (a closure property): never held — it goes out now.
                Log::info('notifications: an unserializable mapped notification was sent instead of held', ['notification' => class_basename($notification)]);
                $hold = false;
                $until = null;
            }
        }

        $class = NotificationModels::delivery();
        $connection = (new $class)->getConnection();

        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction(function () use ($class, $window, $openKey, $now, $recipient, $notification, $type, $subject, $context, $hold, $payload, $until): ?bool {
                    if ($window !== null) {
                        $existing = $class::query()->withTrashed()->where('open_key', $openKey)->lockForUpdate()->first();

                        if ($existing !== null && ! $existing->trashed() && self::absorbs($existing, $now)) {
                            $existing->forceFill(['events_count' => $existing->events_count + 1, 'last_event_at' => $now])->save();

                            return false;
                        }

                        if ($existing !== null) {
                            $existing->forceFill(['open_key' => null])->saveQuietly();
                        }
                    }

                    $delivery = new $class;
                    $delivery->forceFill([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'type' => $type->key(),
                        'channel' => 'mail',
                        'subject_type' => $subject?->getMorphClass(),
                        'subject_id' => $subject?->getKey(),
                        'status' => $hold ? DeliveryStatus::Held : DeliveryStatus::Sending,
                        'open_key' => $window === null ? null : $openKey,
                        'window_ends_at' => $window === null ? null : $now->add($window),
                        'send_after' => $until ?? $now,
                        'events_count' => 1,
                        'first_event_at' => $now,
                        'last_event_at' => $now,
                        'context_key' => $context?->key,
                        'context_label' => $context?->label,
                        'data' => [],
                        'payload' => $payload,
                        'notification_id' => is_string($notification->id) && $notification->id !== '' ? $notification->id : null,
                        'source' => NotificationDelivery::SOURCE_INTEROP,
                        'attempts' => $hold ? 0 : 1,
                        'claimed_at' => $hold ? null : $now,
                    ]);
                    $delivery->getConnection()->transaction(static fn (): bool => $delivery->save());

                    if ($until !== null) {
                        Event::dispatch(new NotificationMailHeld($delivery, $until));

                        return false;
                    }

                    return null;
                });
            } catch (UniqueConstraintViolationException $exception) {
                // Another worker claimed the key first: the next attempt folds into its row.
                if ($attempt >= 3) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Whether a later mapped mail folds into this open row. A `sending` claim counts only while
     * it is fresh: the mail is confirmed (`sent`) within moments, so an older one was cancelled
     * by a `NotificationSending` listener that ran after this package's and never went out.
     */
    private static function absorbs(NotificationDelivery $existing, CarbonImmutable $now): bool
    {
        if ($existing->status->isOpen()) {
            return true;
        }

        $inWindow = $existing->window_ends_at !== null && $now->lessThan($existing->window_ends_at);

        return match ($existing->status) {
            DeliveryStatus::Sent => $inWindow,
            DeliveryStatus::Sending => $inWindow && $existing->claimed_at !== null && $now->lessThan($existing->claimed_at->addSeconds(self::CLAIM_GRACE_SECONDS)),
            default => false,
        };
    }

    public static function isRecipient(Model $notifiable): bool
    {
        if (in_array(ReceivesNotifications::class, class_uses_recursive($notifiable), true)) {
            return true;
        }

        foreach (NotificationsConfig::interopNotifiables() as $class) {
            if ($notifiable instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The notification's own database payload, coerced to plain JSON.
     *
     * @return array<string, mixed>
     */
    private static function data(Notification $notification, Model $recipient): array
    {
        $data = match (true) {
            method_exists($notification, 'toDatabase') => $notification->toDatabase($recipient),
            method_exists($notification, 'toArray') => $notification->toArray($recipient),
            default => [],
        };

        $json = json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR);
        $decoded = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($decoded)) {
            return [];
        }

        $result = [];

        foreach ($decoded as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
