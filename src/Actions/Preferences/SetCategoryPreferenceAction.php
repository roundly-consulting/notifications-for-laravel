<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Preferences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Events\NotificationPreferenceChanged;
use RoundlyConsulting\Notifications\Options\ChannelPreferences;
use RoundlyConsulting\Notifications\Support\Preferences;

/**
 * Sets or clears a channel for a whole category. Mandatory types in it still deliver.
 */
final readonly class SetCategoryPreferenceAction
{
    /**
     * @param  list<Channel>  $channels
     */
    public function execute(Model $user, string $category, array $channels, ?bool $enabled): void
    {
        [$old, $new] = Preferences::write(ChannelPreferences::class, $user, static function (mixed $current) use ($category, $channels, $enabled): array {
            $map = Preferences::normaliseMap($current);
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

        Event::dispatch(new NotificationPreferenceChanged($user, 'category', [$category => $old], [$category => $new]));
    }
}
