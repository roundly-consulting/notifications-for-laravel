<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\ReminderRows;

/**
 * Cancels a subject's pending reminders and escalations (of one type, or all).
 */
final readonly class CancelRemindersAction
{
    public function execute(Model $subject, ?string $type = null, CancelReason $reason = CancelReason::Cancelled): int
    {
        return (int) NotificationModels::reminder()::query()->getConnection()
            ->transaction(static fn (): int => ReminderRows::cancelPending($subject, $type, $reason));
    }
}
