<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Delivery;

use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Jobs\DeliverMailJob;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\DeliveryStates;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\Queues;

/**
 * Dispatches every due pending or held delivery (quiet hours over, trailing window closed, retry
 * backoff passed, held interop mail). Each job claims its row by compare-and-set, so two
 * schedulers never send one twice.
 */
final readonly class DispatchDueDeliveriesAction
{
    public function execute(): int
    {
        $now = Clock::now();

        // On a pending or held row `claimed_at` is when its job was last dispatched: a row whose
        // job is still waiting in the queue is not queued again until `mail.lease` has passed
        // (its job may be lost) — a backlog is not multiplied by every sweep.
        $ids = array_values(NotificationModels::delivery()::query()
            ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Held->value])
            ->where('send_after', '<=', $now->format(Clock::FORMAT))
            ->where(static fn ($query) => $query->whereNull('claimed_at')
                ->orWhere('claimed_at', '<', $now->sub(NotificationsConfig::mailLease())->format(Clock::FORMAT)))
            ->orderBy('send_after')
            ->limit(1000)
            ->pluck('id')
            ->all());

        DeliveryStates::dispatched($ids);

        foreach ($ids as $id) {
            if (is_int($id) || is_string($id)) {
                Queues::dispatch(new DeliverMailJob($id));
            }
        }

        return count($ids);
    }
}
