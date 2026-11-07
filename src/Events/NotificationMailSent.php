<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;

/**
 * A mail went out.
 */
final readonly class NotificationMailSent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public NotificationDelivery $delivery,
    ) {}
}
