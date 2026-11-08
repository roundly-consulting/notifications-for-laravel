<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use RoundlyConsulting\Notifications\DataTransferObjects\PreferencesUpdate;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationDataException;
use RoundlyConsulting\Notifications\Exceptions\InvalidQuietHoursException;
use RoundlyConsulting\Notifications\Exceptions\InvalidTimezoneException;
use RoundlyConsulting\Notifications\Exceptions\LockedNotificationChannelException;
use RoundlyConsulting\Notifications\Exceptions\MandatoryNotificationException;
use RoundlyConsulting\Notifications\Exceptions\NotificationsException;
use RoundlyConsulting\Notifications\Exceptions\UnknownNotificationCategoryException;
use RoundlyConsulting\Notifications\Exceptions\UnknownNotificationTypeException;

/**
 * Everything wrong with a preferences update, found before anything is written — one rule set
 * for the action (throws the first) and the HTTP request (reports each under its field).
 *
 * @internal
 */
final class PreferenceUpdates
{
    /**
     * @return array<string, NotificationsException> field (`channels.<type>.<channel>`, `categories.<category>`, …) → problem
     */
    public static function problems(PreferencesUpdate $update, TypeRegistry $types): array
    {
        $problems = [];

        foreach ($update->channels as $key => $channels) {
            $type = $types->get($key);

            if ($type === null) {
                $problems["channels.{$key}"] = UnknownNotificationTypeException::forKey($key);

                continue;
            }

            foreach ($channels as $channel => $enabled) {
                $case = Channel::tryFrom($channel);

                if ($case === null) {
                    $problems["channels.{$key}"] = InvalidNotificationDataException::unknownChannel($channel);
                } elseif ($enabled === false && $type->mandatory()) {
                    $problems["channels.{$key}.{$channel}"] = MandatoryNotificationException::forType($key);
                } elseif ($enabled === false && ! Preferences::switchable($type, $case)) {
                    $problems["channels.{$key}.{$channel}"] = LockedNotificationChannelException::forChannel($key, $case);
                }
            }
        }

        foreach ($update->categories as $category => $channels) {
            if (! $types->hasCategory($category)) {
                $problems["categories.{$category}"] = UnknownNotificationCategoryException::forCategory($category);

                continue;
            }

            foreach (array_keys($channels) as $channel) {
                if (Channel::tryFrom($channel) === null) {
                    $problems["categories.{$category}"] = InvalidNotificationDataException::unknownChannel($channel);
                }
            }
        }

        $time = $update->digest?->time;

        if ($time !== null && ! NotificationsConfig::isTime($time)) {
            $problems['digest.time'] = InvalidQuietHoursException::invalidTime($time);
        }

        if ($update->setsQuietHours && $update->quietHours !== null) {
            foreach (['start' => $update->quietHours->start, 'end' => $update->quietHours->end] as $field => $value) {
                if (! NotificationsConfig::isTime($value)) {
                    $problems["quiet_hours.{$field}"] = InvalidQuietHoursException::invalidTime($value);
                }
            }

            if ($update->quietHours->start === $update->quietHours->end) {
                $problems['quiet_hours'] = InvalidQuietHoursException::sameStartAndEnd();
            }
        }

        if ($update->setsTimezone && $update->timezone !== null && ! DefaultTimezoneResolver::valid($update->timezone)) {
            $problems['timezone'] = InvalidTimezoneException::forValue($update->timezone);
        }

        return $problems;
    }
}
