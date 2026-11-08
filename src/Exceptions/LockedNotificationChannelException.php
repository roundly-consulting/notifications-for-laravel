<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

use RoundlyConsulting\Notifications\Enums\Channel;

/**
 * An attempt to switch off a channel that cannot be switched off — the inbox while
 * `notifications.preferences.inbox_mutable` is off, or a channel the type does not deliver on.
 * Without it the write would be stored and ignored, and a settings screen would show a switch
 * that does nothing. A mandatory type throws {@see MandatoryNotificationException} instead.
 */
final class LockedNotificationChannelException extends NotificationsException
{
    public static function forChannel(string $type, Channel $channel): self
    {
        return self::make('notification_channel_locked', 422, ['type' => $type, 'channel' => $channel->label()]);
    }

    /**
     * `mute($type)` found no channel of the type that can be switched off.
     */
    public static function nothingToMute(string $type): self
    {
        return self::make('notification_channel_locked', 422, ['type' => $type], 'notification_nothing_to_mute');
    }
}
