<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Interop;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification as Notifications;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Support\Access;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\DeliveryStates;
use RoundlyConsulting\Notifications\Support\InteropGate;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\Subjects;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use Throwable;

/**
 * Releases a mapped notification held for quiet hours, claimed by compare-and-set. The payload is
 * decrypted (MAC-checked) before it is ever unserialized — a tampered one fails, never unserializes.
 * Access and the preference are checked again, then the notification is sent on `mail` through
 * the interop gate (its own `shouldSend()` runs again).
 *
 * @internal
 */
final readonly class ReleaseHeldInteropMailAction
{
    public function __construct(
        private TypeRegistry $types,
    ) {}

    public function execute(int|string $id): void
    {
        if (! DeliveryStates::claim($id, NotificationDelivery::SOURCE_INTEROP)) {
            return;
        }

        $delivery = NotificationModels::delivery()::query()->find($id);

        if ($delivery === null) {
            return;
        }

        try {
            $notification = unserialize(Crypt::decryptString((string) $delivery->payload));
        } catch (DecryptException) {
            DeliveryStates::finish($delivery, DeliveryStatus::Failed, CancelReason::Payload);

            return;
        }

        if (! $notification instanceof Notification) {
            DeliveryStates::finish($delivery, DeliveryStatus::Failed, CancelReason::Payload);

            return;
        }

        $type = $this->types->get($delivery->type);
        $recipient = ModelRefs::find($delivery->recipient_type, $delivery->recipient_id);

        if ($type === null || $recipient === null) {
            DeliveryStates::finish($delivery, DeliveryStatus::Cancelled, $type === null ? CancelReason::UnknownType : CancelReason::RecipientGone);

            return;
        }

        $subject = Subjects::fresh($type, $type->subjectFrom($notification));

        if (($delivery->subject_type !== null && $subject === null)
            || ($subject !== null && ! Access::allows($type, $recipient, $subject, true))) {
            DeliveryStates::finish($delivery, DeliveryStatus::Cancelled, CancelReason::NoAccess);

            return;
        }

        if (! Preferences::enabled($type, Channel::Mail, $recipient)) {
            DeliveryStates::finish($delivery, DeliveryStatus::Cancelled, CancelReason::Muted);

            return;
        }

        try {
            InteropGate::releasing(static fn () => Notifications::sendNow($recipient, $notification, ['mail']));
        } catch (Throwable $exception) {
            report($exception);
            $fresh = $delivery->fresh() ?? $delivery;

            $fresh->attempts >= NotificationsConfig::maxAttempts()
                ? DeliveryStates::finish($fresh, DeliveryStatus::Failed, CancelReason::Transport)
                : DeliveryStates::retry($fresh, Clock::now()->addSeconds(NotificationsConfig::backoff()[0]));

            return;
        }

        DeliveryStates::sent($delivery);
    }
}
