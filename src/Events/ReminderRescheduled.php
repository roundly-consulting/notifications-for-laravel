<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Notifications\Models\NotificationReminder;

/**
 * A pending reminder moved with its anchor date.
 */
final readonly class ReminderRescheduled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public NotificationReminder $reminder,
    ) {}
}
