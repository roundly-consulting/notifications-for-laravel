<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Preferences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\DataTransferObjects\QuietHours;
use RoundlyConsulting\Notifications\Events\NotificationPreferenceChanged;
use RoundlyConsulting\Notifications\Exceptions\InvalidQuietHoursException;
use RoundlyConsulting\Notifications\Options\QuietHoursPreference;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\Preferences;

/**
 * Sets (or clears, with null) a user's quiet hours; held mail is re-timed in the same transaction.
 */
final readonly class SetQuietHoursAction
{
    public function __construct(
        private RecomputeDeferredDeliveriesAction $recompute,
    ) {}

    public function execute(Model $user, ?QuietHours $quietHours): void
    {
        if ($quietHours !== null) {
            foreach ([$quietHours->start, $quietHours->end] as $time) {
                if (! NotificationsConfig::isTime($time)) {
                    throw InvalidQuietHoursException::invalidTime($time);
                }
            }

            if ($quietHours->start === $quietHours->end) {
                throw InvalidQuietHoursException::sameStartAndEnd();
            }
        }

        $old = Preferences::write(
            QuietHoursPreference::class,
            $user,
            static fn (mixed $current): array => [$quietHours === null ? null : ['start' => $quietHours->start, 'end' => $quietHours->end], $current],
            fn () => $this->recompute->execute($user),
        );

        Event::dispatch(new NotificationPreferenceChanged($user, 'quiet_hours', $old, $quietHours === null ? null : ['start' => $quietHours->start, 'end' => $quietHours->end]));
    }
}
