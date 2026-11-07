<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Support\DeliveryStates;
use RoundlyConsulting\Notifications\Support\InteropGate;
use RoundlyConsulting\Notifications\Support\NotificationModels;

/**
 * Moves the claimed interop delivery of a mapped notification to `sent` (or `failed`) once
 * Laravel reports the outcome — never before anything was sent.
 */
final readonly class RecordInteropOutcome
{
    public function handle(NotificationSent|NotificationFailed $event): void
    {
        $id = $event->notification->id;

        // A held mail being released records its own outcome (and retries a failed transport).
        if (InteropGate::isReleasing() || $event->channel !== 'mail' || ! $event->notifiable instanceof Model || ! is_string($id) || $id === '') {
            return;
        }

        $delivery = NotificationModels::delivery()::query()
            ->where('source', NotificationDelivery::SOURCE_INTEROP)
            ->where('notification_id', $id)
            ->where('recipient_type', $event->notifiable->getMorphClass())
            ->where('recipient_id', $event->notifiable->getKey())
            ->where('status', DeliveryStatus::Sending->value)
            ->first();

        if ($delivery === null) {
            return;
        }

        $event instanceof NotificationSent
            ? DeliveryStates::sent($delivery)
            : DeliveryStates::finish($delivery, DeliveryStatus::Failed, CancelReason::Transport);
    }
}
