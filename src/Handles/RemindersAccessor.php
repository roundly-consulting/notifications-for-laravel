<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Handles;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Actions\Reminders\AcknowledgeAction;
use RoundlyConsulting\Notifications\Actions\Reminders\BackfillRemindersAction;
use RoundlyConsulting\Notifications\Actions\Reminders\FireDueRemindersAction;
use RoundlyConsulting\Notifications\DataTransferObjects\FiredReminders;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\NotificationsManager;

/**
 * Reminders across subjects: `Notifications::reminders()->fireDue()`.
 */
final readonly class RemindersAccessor
{
    public function __construct(
        private NotificationsManager $manager,
    ) {}

    public function fireDue(): FiredReminders
    {
        return $this->manager->perform(
            NotificationOperation::FireReminders,
            FireDueRemindersAction::class,
            static fn (FireDueRemindersAction $action): FiredReminders => $action->execute(),
        ) ?? new FiredReminders;
    }

    /**
     * Sync every subject of a type's class — for a type added after the data exists.
     */
    public function backfill(string $type): int
    {
        return (int) $this->manager->perform(
            NotificationOperation::BackfillReminders,
            BackfillRemindersAction::class,
            static fn (BackfillRemindersAction $action): int => $action->execute($type),
            ['type' => $type],
        );
    }

    public function acknowledge(Model $subject, ?string $type = null, ?Model $by = null): int
    {
        return (int) $this->manager->perform(
            NotificationOperation::Acknowledge,
            AcknowledgeAction::class,
            static fn (AcknowledgeAction $action): int => $action->execute($subject, $type, $by),
            ['subject' => $subject, 'type' => $type, 'by' => $by],
        );
    }
}
