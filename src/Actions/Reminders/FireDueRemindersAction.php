<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use RoundlyConsulting\Notifications\DataTransferObjects\FiredReminders;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/**
 * Fires every due reminder and escalation (oldest first, `notifications.reminders.batch` at a
 * time). Each row is claimed by compare-and-set, so two sweeps never fire one row twice.
 */
final readonly class FireDueRemindersAction
{
    public function __construct(
        private FireReminderAction $fireReminder,
        private FireEscalationAction $fireEscalation,
    ) {}

    /**
     * @param  ReminderKind|null  $kind  only reminders or only escalations (`sweep --only`); null fires both
     */
    public function execute(?ReminderKind $kind = null): FiredReminders
    {
        $rows = NotificationModels::reminder()::query()
            ->where('status', ReminderStatus::Pending->value)
            ->when($kind !== null, static fn ($query) => $query->where('kind', $kind?->value))
            ->where('due_at', '<=', Clock::now()->format(Clock::FORMAT))
            ->orderBy('due_at')
            ->limit(NotificationsConfig::reminderBatch())
            ->get(['id', 'kind']);

        $reminders = 0;
        $escalations = 0;

        foreach ($rows as $row) {
            $id = ModelRefs::key($row);

            if ($row->kind === ReminderKind::Escalation) {
                $escalations += $this->fireEscalation->execute($id) ? 1 : 0;
            } else {
                $reminders += $this->fireReminder->execute($id) ? 1 : 0;
            }
        }

        return new FiredReminders($reminders, $escalations);
    }
}
