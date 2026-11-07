<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Preferences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Events\NotificationPreferenceChanged;
use RoundlyConsulting\Notifications\Exceptions\InvalidTimezoneException;
use RoundlyConsulting\Notifications\Options\TimezonePreference;
use RoundlyConsulting\Notifications\Support\DefaultTimezoneResolver;
use RoundlyConsulting\Notifications\Support\Preferences;

/**
 * Sets (or clears) a user's IANA timezone; held and digest mail are re-timed.
 */
final readonly class SetNotificationTimezoneAction
{
    public function __construct(
        private RecomputeDeferredDeliveriesAction $recompute,
    ) {}

    public function execute(Model $user, ?string $timezone): void
    {
        if ($timezone !== null && ! DefaultTimezoneResolver::valid($timezone)) {
            throw InvalidTimezoneException::forValue($timezone);
        }

        $old = Preferences::write(
            TimezonePreference::class,
            $user,
            static fn (mixed $current): array => [$timezone, $current],
            fn () => $this->recompute->execute($user),
        );

        Event::dispatch(new NotificationPreferenceChanged($user, 'timezone', $old, $timezone));
    }
}
