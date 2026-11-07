<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Delivery;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestRunStatus;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Events\NotificationMailFailed;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\DigestRuns;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/**
 * Claims a killed worker left behind. A mail or digest still `sending` past its lease is failed,
 * never re-sent — at most once, because whether it went out is unknown. A reminder still `firing`
 * goes back to pending: its re-fire carries the same idempotency key, so nobody gets it twice.
 *
 * @internal
 */
final readonly class ExpireStaleClaimsAction
{
    public function execute(): int
    {
        $now = Clock::now();
        $expired = 0;

        $deliveries = NotificationModels::delivery()::query()
            ->where('status', DeliveryStatus::Sending->value)
            ->where('claimed_at', '<', $now->sub(NotificationsConfig::mailLease())->format(Clock::FORMAT))
            ->get();

        foreach ($deliveries as $delivery) {
            $changed = NotificationModels::delivery()::query()->whereKey($delivery->getKey())
                ->where('status', DeliveryStatus::Sending->value)
                ->update(['status' => DeliveryStatus::Failed->value, 'cancel_reason' => CancelReason::Unknown->value, 'open_key' => null]);

            if ($changed > 0) {
                $expired++;
                Event::dispatch(new NotificationMailFailed($delivery->refresh(), CancelReason::Unknown));
            }
        }

        $runs = NotificationModels::digestRun()::query()
            ->where('status', DigestRunStatus::Sending->value)
            ->where('claimed_at', '<', $now->sub(NotificationsConfig::digestLease())->format(Clock::FORMAT))
            ->get();

        foreach ($runs as $run) {
            DigestRuns::finish($run, DigestRunStatus::Failed, CancelReason::Unknown);
            $expired++;
        }

        $expired += NotificationModels::reminder()::query()
            ->where('status', ReminderStatus::Firing->value)
            ->where('claimed_at', '<', $now->sub(NotificationsConfig::reminderLease())->format(Clock::FORMAT))
            ->update(['status' => ReminderStatus::Pending->value, 'claimed_at' => null]);

        return $expired;
    }
}
