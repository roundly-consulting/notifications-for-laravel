<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Mail;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The mail of one delivery, rendered by the package before it is handed to Laravel (so a render
 * error is told apart from a transport error). Never queued — the package's own job is. Public so
 * a host test can assert it with `Notification::fake()`:
 *
 * ```php
 * Notification::assertSentTo($user, TypedMailNotification::class, fn ($n) => $n->type === 'security.alert');
 * ```
 */
final class TypedMailNotification extends Notification
{
    public function __construct(
        public readonly string $type,
        public readonly int|string $deliveryId,
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
