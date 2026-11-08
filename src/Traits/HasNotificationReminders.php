<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\SubjectLifecycle;

/**
 * Makes a model a reminder subject: reminders of every type whose subject() is this class follow
 * their date attributes (synced after the commit on create, on a changed anchor and on restore)
 * and are cancelled when the model is deleted. Through IsNotificationSubject it is also the
 * subject-lifecycle hook for `notifications.inbox.on_subject_deleted`. Every call goes through the
 * manager.
 *
 * @phpstan-require-extends Model
 */
trait HasNotificationReminders
{
    use IsNotificationSubject;

    public static function bootHasNotificationReminders(): void
    {
        static::created(static fn (Model $subject) => SubjectLifecycle::saved($subject, created: true));
        static::updated(static fn (Model $subject) => SubjectLifecycle::saved($subject, created: false));

        // Fires only for soft-deleting models.
        static::registerModelEvent('restored', static fn (Model $subject) => SubjectLifecycle::saved($subject, created: true));
    }

    /**
     * @return MorphMany<NotificationReminder, $this>
     */
    public function notificationReminders(): MorphMany
    {
        return $this->morphMany(NotificationModels::reminder(), 'subject');
    }

    public function syncNotificationReminders(): int
    {
        return app(NotificationsManager::class)->for($this)->reminders()->sync();
    }
}
