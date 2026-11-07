<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Mail;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One digest mail: the low-priority items of one period, grouped by context and category.
 */
final class DigestMailNotification extends Notification
{
    public function __construct(
        public readonly int|string $runId,
        public readonly int $count,
        public readonly MailMessage $message,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->message;
    }
}
