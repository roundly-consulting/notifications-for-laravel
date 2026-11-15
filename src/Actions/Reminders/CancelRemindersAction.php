<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\ReminderRows;

/**
 * Cancels a subject's pending reminders and escalations (of one type, or all). With `$for`, only
 * that recipient's repeating chains and the escalations they armed — everyone else keeps theirs, and
 * one-off reminders (shared by their recipients) are left alone. Idempotent: what is already over
 * is not counted again.
 */
final readonly class CancelRemindersAction
{
    public function execute(Model $subject, ?string $type = null, CancelReason $reason = CancelReason::Cancelled, ?Model $for = null): int
    {
        return (int) NotificationModels::reminder()::query()->getConnection()
            ->transaction(static fn (): int => ReminderRows::cancelPending($subject, $type, $reason, recipient: $for));
    }
}
