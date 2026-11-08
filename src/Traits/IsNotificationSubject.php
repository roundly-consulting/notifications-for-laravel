<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Traits;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Support\SubjectLifecycle;

/**
 * Makes a model a notification subject that cleans up after itself: when it is deleted, its inbox
 * items are archived or deleted as `notifications.inbox.on_subject_deleted` says, and its pending
 * ad-hoc reminders are cancelled. For subjects without reminder rules — HasNotificationReminders
 * already includes it. Every call goes through the manager, so `Notifications::fake()` records it.
 *
 * @phpstan-require-extends Model
 */
trait IsNotificationSubject
{
    public static function bootIsNotificationSubject(): void
    {
        static::deleted(static fn (Model $subject) => SubjectLifecycle::deleted($subject));
    }
}
