<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Notifications\Models\NotificationReminder;

/**
 * An escalation went out.
 */
final readonly class EscalationFired implements ShouldDispatchAfterCommit
{
    public function __construct(
        public NotificationReminder $reminder,
    ) {}
}
