<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Listeners;

use Illuminate\Notifications\Events\NotificationSending;
use RoundlyConsulting\Notifications\NotificationsManager;

/**
 * Laravel asks this listener before every notification channel send (after the notification's
 * own `shouldSend()`, at delivery time). False cancels that channel; null lets other listeners
 * and Laravel continue — never true, which would stop later listeners.
 */
final readonly class ApplyNotificationRules
{
    public function __construct(
        private NotificationsManager $notifications,
    ) {}

    public function handle(NotificationSending $event): ?bool
    {
        if (! is_object($event->notifiable)) {
            return null;
        }

        return $this->notifications->applyInterop($event->notifiable, $event->notification, $event->channel) === false ? false : null;
    }
}
