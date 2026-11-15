<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Handles;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Actions\Reminders\CancelRemindersAction;
use RoundlyConsulting\Notifications\Actions\Reminders\ScheduleRemindersAction;
use RoundlyConsulting\Notifications\Actions\Reminders\SnoozeRemindersAction;
use RoundlyConsulting\Notifications\Actions\Reminders\SyncRemindersAction;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Exceptions\InvalidReminderRuleException;
use RoundlyConsulting\Notifications\Exceptions\UnknownNotificationTypeException;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\ReminderRows;
use RoundlyConsulting\Notifications\Support\ReminderSnoozes;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

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
     * `restart: true` starts a repeating chain again for a recipient whose chain already ended for
     * the current anchor (acknowledged, `while()` false, cancelled, limit reached); an open chain
     * is kept either way.
     *
     * @param  RecipientSpec|Model|iterable<mixed>  $to
     * @return list<NotificationReminder>
     */
    public function schedule(string $type, RecipientSpec|Model|iterable $to, bool $restart = false): array
    {
        $recipients = Recipients::from($to);

        return $this->manager->perform(
            NotificationOperation::ScheduleReminders,
            ScheduleRemindersAction::class,
            fn (ScheduleRemindersAction $action): array => $action->execute($this->subject, $type, $recipients, $restart),
            ['subject' => $this->subject, 'type' => $type, 'restart' => $restart],
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
     * Cancel pending reminders and escalations (of one type, or all). `for: $user` cancels only
     * that recipient's repeating chains and their escalations; one-off reminders stay.
     */
    public function cancel(?string $type = null, ?Model $for = null): int
    {
        return (int) $this->manager->perform(
            NotificationOperation::CancelReminders,
            CancelRemindersAction::class,
            fn (CancelRemindersAction $action): int => $action->execute($this->subject, $type, for: $for),
            ['subject' => $this->subject, 'type' => $type, 'recipient' => $for],
        );
    }

    /**
     * Push back one recipient's repeating reminders of the type: the next one is due `$until`, and
     * later ones whole intervals after it; everyone else keeps their slots. No open chain: nothing.
     *
     * @throws UnknownNotificationTypeException
     * @throws InvalidReminderRuleException when `$until` is not in the future, or the type has no
     *                                      repeating rule
     */
    public function snooze(string $type, Model $recipient, CarbonInterface $until): int
    {
        // Checked before the manager, so the fake refuses what production refuses.
        ReminderSnoozes::rules($this->manager->container()->make(TypeRegistry::class)->find($type), $until, Clock::now());

        return (int) $this->manager->perform(
            NotificationOperation::SnoozeReminders,
            SnoozeRemindersAction::class,
            fn (SnoozeRemindersAction $action): int => $action->execute($this->subject, $type, $recipient, $until),
            ['subject' => $this->subject, 'type' => $type, 'recipient' => $recipient, 'until' => $until],
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
