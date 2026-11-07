<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Delivery;

use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Jobs\DeliverMailJob;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
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
        $ids = NotificationModels::delivery()::query()
            ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Held->value])
            ->where('send_after', '<=', Clock::now()->format(Clock::FORMAT))
            ->orderBy('send_after')
            ->limit(1000)
            ->get(['id']);

        foreach ($ids as $delivery) {
            Queues::dispatch(new DeliverMailJob(ModelRefs::key($delivery)));
        }

        return $ids->count();
    }
}
