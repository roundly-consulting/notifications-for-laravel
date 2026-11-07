<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Notifications\Models\NotificationReminder;

/**
 * A reminder went out.
 */
final readonly class ReminderFired implements ShouldDispatchAfterCommit
{
    public function __construct(
        public NotificationReminder $reminder,
    ) {}
}
