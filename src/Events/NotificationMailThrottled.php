<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;

/**
 * A typed mail hit the rate limit: deferred until `$until` (`$deferred`), or skipped — cancelled
 * `rate_limited` — when `notifications.mail.rate_limit.when_limited` is `skip`.
 */
final readonly class NotificationMailThrottled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public NotificationDelivery $delivery,
        public CarbonImmutable $until,
        public bool $deferred,
    ) {}
}
