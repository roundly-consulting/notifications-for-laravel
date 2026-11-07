<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\ReminderRows;

/**
 * Acknowledges a subject's reminders and escalations (of one type, or all): stamps them and
 * cancels the pending escalation rows. Later reminder offsets still fire — an acknowledged
 * 30-day reminder does not silence the 7-day one.
 */
final readonly class AcknowledgeAction
{
    public function execute(Model $subject, ?string $type = null, ?Model $by = null): int
    {
        return (int) NotificationModels::reminder()::query()->getConnection()->transaction(static function () use ($subject, $type, $by): int {
            $now = Clock::now();

            ReminderRows::forSubject($subject, $type)
                ->whereNull('acknowledged_at')
                ->whereIn('status', [ReminderStatus::Pending->value, ReminderStatus::Firing->value, ReminderStatus::Sent->value])
                ->update([
                    'acknowledged_at' => $now->format(Clock::FORMAT),
                    'acknowledger_type' => $by?->getMorphClass(),
                    'acknowledger_id' => $by?->getKey(),
                ]);

            return ReminderRows::cancelPending($subject, $type, CancelReason::Acknowledged, ReminderKind::Escalation);
        });
    }
}
