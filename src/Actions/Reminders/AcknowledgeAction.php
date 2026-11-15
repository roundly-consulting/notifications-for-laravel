<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\ReminderRows;

/**
 * Acknowledges a subject's reminders and escalations (of one type, or all): stamps the ones that
 * fired (or are firing) and cancels the pending escalation rows. Later reminder offsets still fire — an acknowledged
 * 30-day reminder does not silence the 7-day one.
 *
 * Repeating chains (`Reminder::every()`) end on acknowledgement. With `$by` only `$by`'s own chain
 * (and the escalations it armed) ends — everyone else keeps being reminded; without `$by` every
 * chain of the subject ends. One-off reminders and send escalations are acknowledged as before.
 */
final readonly class AcknowledgeAction
{
    public function execute(Model $subject, ?string $type = null, ?Model $by = null): int
    {
        return (int) NotificationModels::reminder()::query()->getConnection()->transaction(static function () use ($subject, $type, $by): int {
            $now = Clock::now();

            self::scope(ReminderRows::forSubject($subject, $type), $by)
                ->whereNull('acknowledged_at')
                // Only what already went out: a pending later offset stays unacknowledged, or the
                // escalation it arms when it fires would be cancelled as acknowledged.
                ->whereIn('status', [ReminderStatus::Firing->value, ReminderStatus::Sent->value])
                ->update([
                    'acknowledged_at' => $now->format(Clock::FORMAT),
                    'acknowledger_type' => $by?->getMorphClass(),
                    'acknowledger_id' => $by?->getKey(),
                ]);

            // Pending escalations, and the open row of each repeating chain in scope.
            $pending = self::scope(ReminderRows::forSubject($subject, $type), $by)
                ->where('status', ReminderStatus::Pending->value)
                ->where(static fn (Builder $query): Builder => $query->where('kind', ReminderKind::Escalation->value)->orWhereNotNull('recipient_type'))
                ->lockForUpdate()
                ->get();

            foreach ($pending as $row) {
                ReminderRows::cancel($row, CancelReason::Acknowledged);
            }

            return $pending->count();
        });
    }

    /**
     * A chain row counts only for its own recipient; rows without one are subject-wide.
     *
     * @param  Builder<NotificationReminder>  $query
     * @return Builder<NotificationReminder>
     */
    private static function scope(Builder $query, ?Model $by): Builder
    {
        if ($by === null) {
            return $query;
        }

        return $query->where(static fn (Builder $query): Builder => $query
            ->whereNull('recipient_type')
            ->orWhere(static fn (Builder $query): Builder => $query->where('recipient_type', $by->getMorphClass())->where('recipient_id', $by->getKey())));
    }
}
