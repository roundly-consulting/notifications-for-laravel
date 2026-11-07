<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Notifications\Models\NotificationReminder;

/**
 * A reminder or escalation was cancelled (see its cancel_reason).
 */
final readonly class ReminderCancelled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public NotificationReminder $reminder,
    ) {}
}
