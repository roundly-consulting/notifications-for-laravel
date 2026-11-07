<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;

/**
 * A mail could not be sent (transport, render, unknown outcome, payload).
 */
final readonly class NotificationMailFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public NotificationDelivery $delivery,
        public CancelReason $reason,
    ) {}
}
