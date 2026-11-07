<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;
use Throwable;

/**
 * The title and url stored with every inbox write, rendered in the recipient's locale — what the
 * inbox shows when the type is no longer registered or its live render throws.
 *
 * @internal
 */
final class Snapshots
{
    public static function title(NotificationType $type, NotificationPayload $payload, ?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return Str::limit($override, 250);
        }

        try {
            $title = Locales::for($payload->recipient, static fn (): string => $type->title($payload));
        } catch (Throwable $exception) {
            report($exception);
            $title = $type->label();
        }

        return Str::limit($title === '' ? $type->label() : $title, 250);
    }

    public static function url(NotificationType $type, NotificationPayload $payload): ?string
    {
        try {
            return Urls::safe(Locales::for($payload->recipient, static fn (): ?string => $type->url($payload)));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
