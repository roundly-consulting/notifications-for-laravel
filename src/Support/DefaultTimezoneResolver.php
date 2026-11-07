<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RoundlyConsulting\Notifications\Contracts\HasNotificationTimezone;
use RoundlyConsulting\Notifications\Contracts\ResolvesTimezone;

/**
 * The first valid IANA timezone of: the recipient's HasNotificationTimezone, the stored
 * preference, the configured attribute (read only when present — never a bare `$user->timezone`,
 * which throws on strict-mode hosts), then `app.timezone`. An invalid id is skipped and logged
 * once per recipient per process; it never throws at delivery time.
 */
final class DefaultTimezoneResolver implements ResolvesTimezone
{
    /** @var array<string, true> */
    private static array $warned = [];

    public function timezone(Model $recipient): string
    {
        $candidates = [
            'contract' => $recipient instanceof HasNotificationTimezone ? $recipient->notificationTimezone() : null,
            'preference' => Preferences::timezone($recipient),
            'attribute' => self::attribute($recipient),
        ];

        foreach ($candidates as $source => $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            if (self::valid($candidate)) {
                return $candidate;
            }

            $this->warn($recipient, $source);
        }

        $app = config('app.timezone');

        return is_string($app) && self::valid($app) ? $app : 'UTC';
    }

    public static function valid(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) || $timezone === 'UTC';
    }

    private static function attribute(Model $recipient): ?string
    {
        $attribute = NotificationsConfig::timezoneAttribute();

        if ($attribute === null) {
            return null;
        }

        $attributes = $recipient->getAttributes();

        if (! array_key_exists($attribute, $attributes)) {
            return null;
        }

        $value = $attributes[$attribute];

        return is_string($value) ? $value : null;
    }

    private function warn(Model $recipient, string $source): void
    {
        $key = ModelRefs::identity($recipient).'|'.$source;

        if (isset(self::$warned[$key])) {
            return;
        }

        self::$warned[$key] = true;

        Log::warning('notifications: ignoring an invalid timezone', ['recipient' => class_basename($recipient), 'source' => $source]);
    }
}
