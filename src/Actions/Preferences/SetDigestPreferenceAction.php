<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Preferences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\DataTransferObjects\DigestPreferenceData;
use RoundlyConsulting\Notifications\Events\NotificationPreferenceChanged;
use RoundlyConsulting\Notifications\Exceptions\InvalidQuietHoursException;
use RoundlyConsulting\Notifications\Options\DigestPreference;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\Preferences;

/**
 * Sets a user's digest frequency (and optional local time). Pending digest items move to the new
 * slot; switching off flushes them as one final digest at the next sweep.
 */
final readonly class SetDigestPreferenceAction
{
    public function __construct(
        private RecomputeDeferredDeliveriesAction $recompute,
    ) {}

    public function execute(Model $user, DigestPreferenceData $preference): void
    {
        if ($preference->time !== null && ! NotificationsConfig::isTime($preference->time)) {
            throw InvalidQuietHoursException::invalidTime($preference->time);
        }

        $old = Preferences::write(
            DigestPreference::class,
            $user,
            static fn (mixed $current): array => [array_filter(['frequency' => $preference->frequency->value, 'time' => $preference->time], static fn (?string $value): bool => $value !== null), $current],
            fn () => $this->recompute->execute($user),
        );

        Event::dispatch(new NotificationPreferenceChanged($user, 'digest', $old, ['frequency' => $preference->frequency->value, 'time' => $preference->time]));
    }
}
