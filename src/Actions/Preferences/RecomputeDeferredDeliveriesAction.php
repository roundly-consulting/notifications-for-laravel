<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Preferences;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Contracts\ResolvesTimezone;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\MailStrategy;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\DigestSchedule;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\QuietHoursClock;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Support\Windows;

/**
 * After a digest, quiet-hours or timezone change: re-time the user's held mail and move their
 * unattached digest items to the new slot — or, digests switched off, to a one-off flush period
 * that goes out as one final digest at the next sweep.
 *
 * @internal
 */
final readonly class RecomputeDeferredDeliveriesAction
{
    public function execute(Model $user): void
    {
        $class = NotificationModels::delivery();
        $now = Clock::now();
        $timezone = app(ResolvesTimezone::class)->timezone($user);
        $quiet = Preferences::quietHours($user);

        $held = $class::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->where('status', DeliveryStatus::Held->value)
            ->lockForUpdate()
            ->get();

        foreach ($held as $delivery) {
            $delivery->send_after = QuietHoursClock::after($this->earliest($delivery, $now), $timezone, $quiet);
            $delivery->save();
        }

        $digest = Preferences::digest($user);

        $parked = $class::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->where('status', DeliveryStatus::Digest->value)
            ->whereNull('digest_run_id')
            ->lockForUpdate()
            ->get();

        foreach ($parked as $delivery) {
            if ($digest->frequency === DigestFrequency::Off) {
                $delivery->forceFill([
                    'digest_frequency' => $delivery->digest_frequency ?? DigestFrequency::Daily,
                    'digest_period' => DigestSchedule::flushPeriod($now),
                    'send_after' => $now,
                ])->save();

                continue;
            }

            [$due, $period] = DigestSchedule::next($now, $timezone, $digest->frequency, $digest->time ?? NotificationsConfig::digestTime(), $quiet);

            $delivery->forceFill([
                'digest_frequency' => $digest->frequency,
                'digest_period' => $period,
                'send_after' => $due,
            ])->save();
        }
    }

    /**
     * When the held mail may go out at the earliest: a trailing mail waits for its window to close
     * (it carries the window's final count), anything else is due now.
     */
    private function earliest(NotificationDelivery $delivery, CarbonImmutable $now): CarbonImmutable
    {
        $type = $delivery->source === NotificationDelivery::SOURCE_TYPE ? app(TypeRegistry::class)->get($delivery->type) : null;

        if ($type !== null && Windows::strategy($type) === MailStrategy::Trailing
            && $delivery->window_ends_at !== null && $delivery->window_ends_at->greaterThan($now)) {
            return $delivery->window_ends_at;
        }

        return $now;
    }
}
