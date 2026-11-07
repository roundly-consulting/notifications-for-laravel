<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Traits;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Handles\InboxHandle;
use RoundlyConsulting\Notifications\Handles\PreferencesHandle;
use RoundlyConsulting\Notifications\NotificationsManager;

/**
 * Makes a model (your User) a recipient: `$user->inbox()`, `$user->notificationPreferences()`.
 * Every call goes through the manager, so `Notifications::fake()` sees it. Named so it composes
 * with Laravel's `Notifiable` (no `notifications()` / `notify()` clash) — keep `Notifiable` for
 * mail routing.
 *
 * @phpstan-require-extends Model
 */
trait ReceivesNotifications
{
    public function inbox(): InboxHandle
    {
        return app(NotificationsManager::class)->inbox($this);
    }

    public function notificationPreferences(): PreferencesHandle
    {
        return app(NotificationsManager::class)->preferences($this);
    }

    public function unreadNotificationCount(): int
    {
        return app(NotificationsManager::class)->inbox($this)->unreadCount();
    }
}
