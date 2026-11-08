<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Preferences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\DataTransferObjects\PreferencesUpdate;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Events\NotificationPreferenceChanged;
use RoundlyConsulting\Notifications\Options\ChannelPreferences;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\PreferenceUpdates;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * Applies several preference changes at once — all or nothing. Everything is validated before the
 * first write (an unknown type, category or channel, a mandatory type switched off, a bad time or
 * timezone throws, and so does switching off a locked channel), then every write runs under one preference lock in one transaction, so a
 * failure half way leaves the user's settings as they were. One `NotificationPreferenceChanged`
 * per changed type, category and setting, after the commit.
 */
final readonly class UpdatePreferencesAction
{
    public function __construct(
        private TypeRegistry $types,
        private SetDigestPreferenceAction $digest,
        private SetQuietHoursAction $quietHours,
        private SetNotificationTimezoneAction $timezone,
    ) {}

    public function execute(Model $user, PreferencesUpdate $update): void
    {
        foreach (PreferenceUpdates::problems($update, $this->types) as $problem) {
            throw $problem;
        }

        Preferences::atomically($user, function () use ($user, $update): void {
            if ($update->channels !== [] || $update->categories !== []) {
                $events = Preferences::write(ChannelPreferences::class, $user, fn (mixed $current): array => $this->apply($current, $user, $update));

                // Dispatched inside the transaction: the events wait for its commit and vanish
                // with a rollback.
                foreach ($events as $event) {
                    Event::dispatch($event);
                }
            }

            if ($update->digest !== null) {
                $this->digest->execute($user, $update->digest);
            }

            if ($update->setsQuietHours) {
                $this->quietHours->execute($user, $update->quietHours);
            }

            if ($update->setsTimezone) {
                $this->timezone->execute($user, $update->timezone);
            }
        });
    }

    /**
     * @return array{0: array{types: array<string, array<string, bool>>, categories: array<string, array<string, bool>>}, 1: list<NotificationPreferenceChanged>}
     */
    private function apply(mixed $current, Model $user, PreferencesUpdate $update): array
    {
        $map = Preferences::cleanMap($current, $this->types);
        $events = [];

        foreach ($update->channels as $type => $channels) {
            $definition = $this->types->find($type);
            $old = $map['types'][$type] ?? [];

            foreach ($channels as $channel => $enabled) {
                // Validated: a `false` here is switchable; a `true` on a locked channel stores nothing.
                if (Preferences::switchable($definition, Channel::from($channel))) {
                    $map['types'][$type][$channel] = $enabled;
                }
            }

            $new = $map['types'][$type] ?? [];

            if (! Preferences::sameChannels($old, $new)) {
                $events[] = new NotificationPreferenceChanged($user, 'type', [$type => $old], [$type => $new]);
            }
        }

        foreach ($update->categories as $category => $channels) {
            $old = $map['categories'][$category] ?? [];

            foreach ($channels as $channel => $enabled) {
                if ($enabled) {
                    unset($map['categories'][$category][$channel]);
                } else {
                    $map['categories'][$category][$channel] = false;
                }
            }

            if (($map['categories'][$category] ?? null) === []) {
                unset($map['categories'][$category]);
            }

            $new = $map['categories'][$category] ?? [];

            if (! Preferences::sameChannels($old, $new)) {
                $events[] = new NotificationPreferenceChanged($user, 'category', [$category => $old], [$category => $new]);
            }
        }

        return [$map, $events];
    }
}
