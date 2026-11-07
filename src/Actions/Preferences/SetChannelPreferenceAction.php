<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Preferences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Events\NotificationPreferenceChanged;
use RoundlyConsulting\Notifications\Exceptions\MandatoryNotificationException;
use RoundlyConsulting\Notifications\Options\ChannelPreferences;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * Sets (true/false) or clears (null — back to the category and type default) one channel of one
 * type for one user. Muting a mandatory type throws.
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

        [$old, $new] = Preferences::write(ChannelPreferences::class, $user, function (mixed $current) use ($type, $channels, $enabled): array {
            $map = $this->clean(Preferences::normaliseMap($current));
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

        Event::dispatch(new NotificationPreferenceChanged($user, 'type', [$type => $old], [$type => $new]));
    }

    /**
     * Drops overrides of types that are no longer registered.
     *
     * @param  array{types: array<string, array<string, bool>>, categories: array<string, array<string, bool>>}  $map
     * @return array{types: array<string, array<string, bool>>, categories: array<string, array<string, bool>>}
     */
    private function clean(array $map): array
    {
        foreach (array_keys($map['types']) as $key) {
            if (! $this->types->has($key)) {
                unset($map['types'][$key]);
            }
        }

        return $map;
    }
}
