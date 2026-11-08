<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Preferences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Events\NotificationPreferenceChanged;
use RoundlyConsulting\Notifications\Exceptions\LockedNotificationChannelException;
use RoundlyConsulting\Notifications\Exceptions\MandatoryNotificationException;
use RoundlyConsulting\Notifications\Options\ChannelPreferences;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * Sets (true/false) or clears (null — back to the category and type default) one channel of one
 * type for one user. Muting a mandatory type throws; so does switching off a locked channel (the
 * inbox unless inbox muting is on) or a channel the type lacks, and muting with no channel left.
 * Switching such a channel on stores nothing — it is always on, or never there. The event fires
 * only when the stored override changed.
 */
final readonly class SetChannelPreferenceAction
{
    public function __construct(
        private TypeRegistry $types,
    ) {}

    /**
     * @param  list<Channel>  $channels
     */
    public function execute(Model $user, string $type, array $channels, ?bool $enabled): void
    {
        $definition = $this->types->find($type);

        if ($enabled === false && $definition->mandatory()) {
            throw MandatoryNotificationException::forType($type);
        }

        if ($enabled === false && $channels === []) {
            throw LockedNotificationChannelException::nothingToMute($type);
        }

        foreach ($channels as $channel) {
            if ($enabled === false && ! Preferences::switchable($definition, $channel)) {
                throw LockedNotificationChannelException::forChannel($type, $channel);
            }
        }

        if ($enabled === true) {
            $channels = array_values(array_filter($channels, static fn (Channel $channel): bool => Preferences::switchable($definition, $channel)));

            if ($channels === []) {
                return;
            }
        }

        [$old, $new] = Preferences::write(ChannelPreferences::class, $user, function (mixed $current) use ($type, $channels, $enabled): array {
            $map = Preferences::cleanMap($current, $this->types);
            $old = $map['types'][$type] ?? [];

            foreach ($channels as $channel) {
                if ($enabled === null) {
                    unset($map['types'][$type][$channel->value]);
                } else {
                    $map['types'][$type][$channel->value] = $enabled;
                }
            }

            if (($map['types'][$type] ?? null) === []) {
                unset($map['types'][$type]);
            }

            return [$map, [$old, $map['types'][$type] ?? []]];
        });

        if (! Preferences::sameChannels($old, $new)) {
            Event::dispatch(new NotificationPreferenceChanged($user, 'type', [$type => $old], [$type => $new]));
        }
    }
}
