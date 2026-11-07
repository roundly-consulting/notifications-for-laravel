<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Events\ReminderCancelled;
use RoundlyConsulting\Notifications\Events\ReminderRescheduled;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;

/**
 * Reminder row queries and transitions. Every terminal status nulls `active_key` in the same
 * statement, so a closed row never blocks the next schedule of its key.
 *
 * @internal
 */
final class ReminderRows
{
    /**
     * @return Builder<NotificationReminder>
     */
    public static function forSubject(Model $subject, ?string $type = null): Builder
    {
        return NotificationModels::reminder()::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->when($type !== null, static fn (Builder $query): Builder => $query->where('type', $type));
    }

    public static function cancel(NotificationReminder $row, CancelReason $reason, ReminderStatus $status = ReminderStatus::Cancelled): void
    {
        $row->forceFill([
            'status' => $status,
            'cancel_reason' => $reason,
            'active_key' => null,
            'claimed_at' => null,
        ])->save();

        Event::dispatch(new ReminderCancelled($row));
    }

    public static function reschedule(NotificationReminder $row, CarbonImmutable $due, ?CarbonImmutable $anchor): void
    {
        $row->forceFill([
            'status' => ReminderStatus::Pending,
            'due_at' => $due,
            'anchor_value' => $anchor,
            'claimed_at' => null,
        ])->save();

        Event::dispatch(new ReminderRescheduled($row));
    }

    /**
     * Cancel every pending reminder and escalation of a subject (and type).
     */
    public static function cancelPending(Model $subject, ?string $type, CancelReason $reason, ?ReminderKind $kind = null): int
    {
        $rows = self::forSubject($subject, $type)
            ->where('status', ReminderStatus::Pending->value)
            ->when($kind !== null, static fn (Builder $query): Builder => $query->where('kind', $kind?->value))
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            self::cancel($row, $reason);
        }

        return $rows->count();
    }

    /**
     * The rule a row came from, if its type still declares it.
     */
    public static function rule(NotificationType $type, ?string $ruleKey): ?ReminderRule
    {
        if ($ruleKey === null) {
            return null;
        }

        foreach ($type->reminders() as $rule) {
            if ($rule->ruleKey() === $ruleKey) {
                return $rule;
            }
        }

        return null;
    }

    public static function isAdHoc(?string $rule): bool
    {
        return $rule !== null && str_starts_with($rule, 'adhoc:');
    }
}
