<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Handles;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Actions\Reminders\CancelRemindersAction;
use RoundlyConsulting\Notifications\Actions\Reminders\ScheduleRemindersAction;
use RoundlyConsulting\Notifications\Actions\Reminders\SyncRemindersAction;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Support\ReminderRows;

/**
 * One subject's reminders: `Notifications::for($contract)->reminders()->pending()`.
 */
final readonly class SubjectReminders
{
    public function __construct(
        private NotificationsManager $manager,
        private Model $subject,
    ) {}

    /**
     * Schedule a type's rules with explicit recipients (rules written without `->to()`).
     *
     * @param  RecipientSpec|Model|iterable<mixed>  $to
     * @return list<NotificationReminder>
     */
    public function schedule(string $type, RecipientSpec|Model|iterable $to): array
    {
        $recipients = Recipients::from($to);

        return $this->manager->perform(
            NotificationOperation::ScheduleReminders,
            ScheduleRemindersAction::class,
            fn (ScheduleRemindersAction $action): array => $action->execute($this->subject, $type, $recipients),
            ['subject' => $this->subject, 'type' => $type],
        ) ?? [];
    }

    /**
     * Re-time pending reminders to the subject's dates and schedule new offsets.
     */
    public function sync(?string $type = null): int
    {
        return (int) $this->manager->perform(
            NotificationOperation::SyncReminders,
            SyncRemindersAction::class,
            fn (SyncRemindersAction $action): int => $action->execute($this->subject, $type),
            ['subject' => $this->subject, 'type' => $type],
        );
    }

    /**
     * Cancel pending reminders and escalations (of one type, or all).
     */
    public function cancel(?string $type = null): int
    {
        return (int) $this->manager->perform(
            NotificationOperation::CancelReminders,
            CancelRemindersAction::class,
            fn (CancelRemindersAction $action): int => $action->execute($this->subject, $type),
            ['subject' => $this->subject, 'type' => $type],
        );
    }

    /**
     * @return Collection<int, NotificationReminder>
     */
    public function pending(?string $type = null): Collection
    {
        return ReminderRows::forSubject($this->subject, $type)
            ->where('status', ReminderStatus::Pending->value)
            ->orderBy('due_at')
            ->get();
    }

    /**
     * @return Collection<int, NotificationReminder>
     */
    public function all(?string $type = null): Collection
    {
        return ReminderRows::forSubject($this->subject, $type)->orderBy('due_at')->get();
    }
}
