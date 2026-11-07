<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use RoundlyConsulting\Notifications\NotificationsManager;

/**
 * The Laravel `inbox` channel: a host notification with `via() => ['inbox']` and
 * `toInbox($notifiable): InboxMessage` lands in the inbox through the same collapse step — the
 * type's access check and inbox preference apply.
 */
final readonly class InboxChannel
{
    public function __construct(
        private NotificationsManager $notifications,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof Model || ! method_exists($notification, 'toInbox')) {
            return;
        }

        $message = $notification->toInbox($notifiable);

        if ($message instanceof InboxMessage) {
            $this->notifications->inboxMessage($notifiable, $message);
        }
    }
}
