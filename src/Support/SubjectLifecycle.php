<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Actions\Inbox\ForgetSubjectInboxAction;
use RoundlyConsulting\Notifications\Actions\Reminders\CancelRemindersAction;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\NotificationType;
use Throwable;

/**
 * What HasNotificationReminders does on the subject's save path. Sync runs after the host's
 * commit, so a lock wait or a lost race can never fail or roll back the host's save; only a
 * programmer error (an invalid rule) is rethrown.
 *
 * @internal
 */
final class SubjectLifecycle
{
    public static function saved(Model $subject, bool $created): void
    {
        $types = app(TypeRegistry::class)->forSubject($subject);

        if ($types === []) {
            return;
        }

        if (! $created && ! self::anchorChanged($subject, $types)) {
            return;
        }

        $subject->getConnection()->afterCommit(static function () use ($subject): void {
            try {
                app(NotificationsManager::class)->for($subject)->reminders()->sync();
            } catch (Throwable $exception) {
                // A lost race is absorbed: the fire step re-reads the anchor anyway.
                report($exception);
            }
        });
    }

    public static function deleted(Model $subject): void
    {
        $manager = app(NotificationsManager::class);

        if (app(TypeRegistry::class)->forSubject($subject) !== []
            || ReminderRows::forSubject($subject)->where('status', ReminderStatus::Pending->value)->exists()) {
            $manager->perform(
                NotificationOperation::CancelReminders,
                CancelRemindersAction::class,
                static fn (CancelRemindersAction $action): int => $action->execute($subject, null, CancelReason::SubjectDeleted),
                ['subject' => $subject, 'type' => null],
            );
        }

        $mode = NotificationsConfig::onSubjectDeleted();

        if ($mode === 'keep') {
            return;
        }

        // Through the manager, so Notifications::fake() records it instead of writing.
        $manager->perform(
            NotificationOperation::SubjectDeleted,
            ForgetSubjectInboxAction::class,
            static fn (ForgetSubjectInboxAction $action): int => $action->execute($subject, $mode),
            ['subject' => $subject, 'mode' => $mode],
        );
    }

    /**
     * @param  list<NotificationType>  $types
     */
    private static function anchorChanged(Model $subject, array $types): bool
    {
        foreach ($types as $type) {
            foreach ($type->reminders() as $rule) {
                if ($rule->attribute !== null && $subject->wasChanged($rule->attribute)) {
                    return true;
                }
            }
        }

        // Ad-hoc reminders anchored to any attribute follow it too.
        $adHoc = ReminderRows::forSubject($subject)
            ->where('status', ReminderStatus::Pending->value)
            ->whereNotNull('anchor')
            ->pluck('anchor')
            ->all();

        foreach ($adHoc as $attribute) {
            if (is_string($attribute) && $subject->wasChanged($attribute)) {
                return true;
            }
        }

        return false;
    }
}
