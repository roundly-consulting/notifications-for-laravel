<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Handles;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Actions\Inbox\RevokeNotificationsAction;
use RoundlyConsulting\Notifications\Actions\Reminders\AcknowledgeAction;
use RoundlyConsulting\Notifications\Actions\Reminders\RemindAction;
use RoundlyConsulting\Notifications\Builders\PendingNotification;
use RoundlyConsulting\Notifications\Context;
use RoundlyConsulting\Notifications\DataTransferObjects\SendResult;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;

/**
 * Everything about one subject: `Notifications::for($contract)`.
 */
final readonly class SubjectHandle
{
    public function __construct(
        private NotificationsManager $manager,
        private Model $subject,
    ) {}

    public function subject(): Model
    {
        return $this->subject;
    }

    /**
     * @param  RecipientSpec|Model|Closure|iterable<mixed>  $recipients
     * @param  array<string, mixed>  $data
     */
    public function send(
        string $type,
        RecipientSpec|Model|Closure|iterable $recipients,
        array $data = [],
        ?Model $actor = null,
        Context|Model|string|null $context = null,
        ?string $idempotencyKey = null,
    ): SendResult {
        return $this->manager->send($type, $this->subject, $recipients, $data, $actor, $context, $idempotencyKey);
    }

    public function type(string $type): PendingNotification
    {
        return $this->manager->type($type)->about($this->subject);
    }

    /**
     * An ad-hoc reminder: `remind('task.due', Reminder::before('deadline', 'PT1H'), to: $user)`.
     *
     * @param  RecipientSpec|Model|iterable<mixed>  $to
     * @param  array<string, mixed>  $data
     * @return list<NotificationReminder>
     */
    public function remind(string $type, ReminderRule $rule, RecipientSpec|Model|iterable $to, array $data = []): array
    {
        $recipients = Recipients::from($to);

        return $this->manager->perform(
            NotificationOperation::Remind,
            RemindAction::class,
            fn (RemindAction $action): array => $action->execute($this->subject, $type, $rule, $recipients, $data),
            ['subject' => $this->subject, 'type' => $type],
        ) ?? [];
    }

    public function reminders(): SubjectReminders
    {
        return new SubjectReminders($this->manager, $this->subject);
    }

    /**
     * Stops escalations of this subject (of one type, or all); returns escalations cancelled.
     */
    public function acknowledge(?string $type = null, ?Model $by = null): int
    {
        return (int) $this->manager->perform(
            NotificationOperation::Acknowledge,
            AcknowledgeAction::class,
            fn (AcknowledgeAction $action): int => $action->execute($this->subject, $type, $by),
            ['subject' => $this->subject, 'type' => $type, 'by' => $by],
        );
    }

    /**
     * A recipient lost access: remove their inbox items for this subject and cancel their
     * pending mail. Without a recipient, for everyone (and the subject's pending reminders).
     */
    public function revoke(?Model $recipient = null): int
    {
        return (int) $this->manager->perform(
            NotificationOperation::Revoke,
            RevokeNotificationsAction::class,
            fn (RevokeNotificationsAction $action): int => $action->execute($this->subject, $recipient),
            ['subject' => $this->subject, 'recipient' => $recipient],
        );
    }
}
