<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Delivery;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Notifications\Contracts\ResolvesTimezone;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Mail\TypedMailNotification;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Support\Access;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\DeliveryPayloads;
use RoundlyConsulting\Notifications\Support\DeliveryStates;
use RoundlyConsulting\Notifications\Support\Locales;
use RoundlyConsulting\Notifications\Support\MailAddresses;
use RoundlyConsulting\Notifications\Support\MailRenderer;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\QuietHoursClock;
use RoundlyConsulting\Notifications\Support\Subjects;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use Throwable;

/**
 * Sends one due delivery, at most once. The row is claimed by compare-and-set (`pending|held →
 * sending`), so a retried or re-dispatched job, or a second scheduler, sends nothing. Before
 * sending it re-checks the recipient, type, subject, access, mutes and quiet hours, and resolves
 * the address itself (Laravel's mail channel returns silently on a null route).
 *
 * @internal
 */
final readonly class DeliverMailAction
{
    public function __construct(
        private TypeRegistry $types,
    ) {}

    public function execute(int|string $id): void
    {
        if (! DeliveryStates::claim($id, NotificationDelivery::SOURCE_TYPE)) {
            return;
        }

        $delivery = NotificationModels::delivery()::query()->find($id);

        if ($delivery === null) {
            return;
        }

        $type = $this->types->get($delivery->type);

        if ($type === null) {
            DeliveryStates::finish($delivery, DeliveryStatus::Cancelled, CancelReason::UnknownType);

            return;
        }

        $recipient = ModelRefs::find($delivery->recipient_type, $delivery->recipient_id);

        if ($recipient === null) {
            DeliveryStates::finish($delivery, DeliveryStatus::Cancelled, CancelReason::RecipientGone);

            return;
        }

        $subjectGiven = $delivery->subject_type !== null;
        $subject = $subjectGiven ? Subjects::find($type, $delivery->subject_type, $delivery->subject_id) : null;

        if ($subjectGiven && $subject === null) {
            DeliveryStates::finish($delivery, DeliveryStatus::Cancelled, CancelReason::SubjectGone);

            return;
        }

        if (! Access::allows($type, $recipient, $subject, $subjectGiven)) {
            DeliveryStates::finish($delivery, DeliveryStatus::Cancelled, CancelReason::NoAccess);

            return;
        }

        if (! Preferences::enabled($type, Channel::Mail, $recipient)) {
            DeliveryStates::finish($delivery, DeliveryStatus::Cancelled, CancelReason::Muted);

            return;
        }

        if ($type->priority() !== Priority::Urgent && ($quiet = Preferences::quietHours($recipient)) !== null) {
            $timezone = app(ResolvesTimezone::class)->timezone($recipient);
            $now = Clock::now();

            if (QuietHoursClock::isQuiet($now, $timezone, $quiet)) {
                DeliveryStates::hold($delivery, QuietHoursClock::end($now, $timezone, $quiet));

                return;
            }
        }

        $payload = DeliveryPayloads::payload($delivery, $recipient, $subject);

        try {
            $message = Locales::for($recipient, static fn () => MailRenderer::typed($type, $payload));
            $address = MailAddresses::resolve($recipient, $payload);
        } catch (Throwable $exception) {
            report($exception);
            DeliveryStates::finish($delivery, DeliveryStatus::Failed, CancelReason::Render);

            return;
        }

        $notification = new TypedMailNotification($type->key(), $id, $message);

        if ($address === false) {
            DeliveryStates::finish($delivery, DeliveryStatus::Undeliverable, CancelReason::NoAddress);

            return;
        }

        try {
            if ($address === true) {
                Notification::sendNow($recipient, $notification);
            } else {
                $locale = Locales::preferred($recipient);

                if ($locale !== null) {
                    $notification->locale($locale);
                }

                (new AnonymousNotifiable)->route('mail', $address)->notifyNow($notification);
            }
        } catch (Throwable $exception) {
            report($exception);
            self::retryOrFail($delivery);

            return;
        }

        DeliveryStates::sent($delivery);
    }

    private static function retryOrFail(NotificationDelivery $delivery): void
    {
        $fresh = $delivery->fresh() ?? $delivery;
        $attempts = $fresh->attempts;

        if ($attempts >= NotificationsConfig::maxAttempts()) {
            DeliveryStates::finish($fresh, DeliveryStatus::Failed, CancelReason::Transport);

            return;
        }

        $backoff = NotificationsConfig::backoff();
        $seconds = $backoff[min($attempts - 1, count($backoff) - 1)] ?? end($backoff);

        DeliveryStates::retry($fresh, Clock::now()->addSeconds(max(1, (int) $seconds)));
    }
}
