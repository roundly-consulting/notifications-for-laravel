<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Preferences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Events\NotificationPreferenceChanged;
use RoundlyConsulting\Notifications\Exceptions\LockedNotificationChannelException;
use RoundlyConsulting\Notifications\Exceptions\UnknownNotificationCategoryException;
use RoundlyConsulting\Notifications\Options\ChannelPreferences;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * Sets or clears a channel for a whole category. Mandatory types in it still deliver. A category
 * no registered type uses throws.
 */
final readonly class SetCategoryPreferenceAction
{
    public function __construct(
        private TypeRegistry $types,
    ) {}

    /**
     * @param  list<Channel>  $channels
     */
    public function execute(Model $user, string $category, array $channels, ?bool $enabled): void
    {
        if (! $this->types->hasCategory($category)) {
            throw UnknownNotificationCategoryException::forCategory($category);
        }

        foreach ($channels as $channel) {
            if ($enabled === false && Preferences::categoryLocked($channel)) {
                throw LockedNotificationChannelException::forCategory($category, $channel);
            }
        }

        // Switching a locked channel on stores nothing: it is always on.
        if ($enabled === true) {
            $channels = array_values(array_filter($channels, static fn (Channel $channel): bool => ! Preferences::categoryLocked($channel)));

            if ($channels === []) {
                return;
            }
        }

        [$old, $new] = Preferences::write(ChannelPreferences::class, $user, function (mixed $current) use ($category, $channels, $enabled): array {
            $map = Preferences::cleanMap($current, $this->types);
            $old = $map['categories'][$category] ?? [];

            foreach ($channels as $channel) {
                if ($enabled === null) {
                    unset($map['categories'][$category][$channel->value]);
                } else {
                    $map['categories'][$category][$channel->value] = $enabled;
                }
            }

            if (($map['categories'][$category] ?? null) === []) {
                unset($map['categories'][$category]);
            }

            return [$map, [$old, $map['categories'][$category] ?? []]];
        });

        if (! Preferences::sameChannels($old, $new)) {
            Event::dispatch(new NotificationPreferenceChanged($user, 'category', [$category => $old], [$category => $new]));
        }
    }
}
