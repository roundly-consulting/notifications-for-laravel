<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;

/**
 * A mail is held until quiet hours end.
 */
final readonly class NotificationMailHeld implements ShouldDispatchAfterCommit
{
    public function __construct(
        public NotificationDelivery $delivery,
        public CarbonImmutable $until,
    ) {}
}
