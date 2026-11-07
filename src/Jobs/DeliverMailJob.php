<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\Notifications\Actions\Delivery\DeliverMailAction;
use RoundlyConsulting\Notifications\Actions\Interop\ReleaseHeldInteropMailAction;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Support\NotificationModels;

/**
 * Sends one due delivery. One try: the compare-and-set claim, not a unique lock, is the guard —
 * a re-dispatch after `sent` is a no-op, and a lock left by a killed worker would silently drop
 * every later dispatch of the id.
 */
final class DeliverMailJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int|string $deliveryId,
    ) {}

    public function handle(DeliverMailAction $deliver, ReleaseHeldInteropMailAction $release): void
    {
        $source = NotificationModels::delivery()::query()->whereKey($this->deliveryId)->value('source');

        $source === NotificationDelivery::SOURCE_INTEROP
            ? $release->execute($this->deliveryId)
            : $deliver->execute($this->deliveryId);
    }
}
