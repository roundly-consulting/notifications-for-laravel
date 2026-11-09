<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;

/**
 * Builds the mail of a delivery, in the current (the recipient's) locale. Type text goes through
 * Laravel's markdown template, which escapes it — the package never wraps it in HtmlString. With
 * `notifications.mail.list_unsubscribe` on, a non-mandatory type's mail carries the
 * List-Unsubscribe headers.
 *
 * @internal
 */
final class MailRenderer
{
    public static function typed(NotificationType $type, NotificationPayload $payload): MailMessage
    {
        $url = Urls::safe($type->url($payload));

        if (self::minimal($type, $payload->subject)) {
            $message = (new MailMessage)
                ->subject($type->minimalTitle())
                ->greeting(self::greeting($payload->recipient))
                ->line(Messages::get('mail.minimal_line', ['app' => Messages::appName()]));

            if ($url !== null) {
                $message->action($type->actionText($payload), $url);
            }

            return UnsubscribeLinks::apply(self::footer($message, $type), $payload->recipient, $type);
        }

        $message = (new MailMessage)
            ->subject($type->title($payload))
            ->greeting(self::greeting($payload->recipient));

        $body = $type->body($payload);

        $message->line($body ?? $type->title($payload));

        if ($payload->count > 1) {
            $message->line(Messages::choice('mail.more_updates', $payload->count - 1, ['count' => $payload->count - 1]));
        }

        if ($url !== null) {
            $message->action($type->actionText($payload), $url);
        }

        return UnsubscribeLinks::apply($type->mail(self::footer($message, $type), $payload), $payload->recipient, $type);
    }

    public static function minimal(NotificationType $type, ?Model $subject): bool
    {
        return NotificationsConfig::minimalForSensitive() && $type->sensitive($subject);
    }

    public static function greeting(Model $recipient): string
    {
        $attributes = $recipient->getAttributes();
        $name = array_key_exists('name', $attributes) && is_string($attributes['name']) ? trim($attributes['name']) : '';

        return $name === ''
            ? Messages::get('mail.greeting_anonymous')
            : Messages::get('mail.greeting', ['name' => $name]);
    }

    public static function footer(MailMessage $message, ?NotificationType $type): MailMessage
    {
        if ($type !== null && $type->mandatory()) {
            return $message->line(Messages::get('mail.mandatory_footer'));
        }

        $url = self::preferencesUrl();

        return $url === null ? $message : $message->line(Messages::get('mail.preferences_footer', ['url' => $url]));
    }

    public static function preferencesUrl(): ?string
    {
        $configured = NotificationsConfig::preferencesUrl();

        if ($configured === null) {
            return null;
        }

        if (Route::has($configured)) {
            return URL::route($configured);
        }

        return Urls::safe($configured);
    }
}
