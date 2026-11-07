<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Events\NotificationMailFailed;
use RoundlyConsulting\Notifications\Events\NotificationMailHeld;
use RoundlyConsulting\Notifications\Events\NotificationMailSent;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;

/**
 * Compare-and-set status transitions of a claimed delivery. Every terminal status closes the
 * open key in the same statement — except `sent` while its window still runs, so later events
 * are counted as de-duplicated instead of mailing again.
 *
 * @internal
 */
final class DeliveryStates
{
    /**
     * Claim a due row for sending: `pending|held → sending`. False when another worker (or an
     * earlier run of this job) got it first.
     */
    public static function claim(int|string $id, string $source): bool
    {
        $now = Clock::now();

        return NotificationModels::delivery()::query()
            ->whereKey($id)
            ->where('source', $source)
            ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Held->value])
            ->where('send_after', '<=', $now->format(Clock::FORMAT))
            ->incrementEach(['attempts' => 1], ['status' => DeliveryStatus::Sending->value, 'claimed_at' => $now->format(Clock::FORMAT)]) > 0;
    }

    public static function sent(NotificationDelivery $delivery): void
    {
        $now = Clock::now();
        $keepKey = $delivery->window_ends_at !== null && $now->lessThan($delivery->window_ends_at);

        if (self::transition($delivery, DeliveryStatus::Sending, [
            'status' => DeliveryStatus::Sent->value,
            'sent_at' => $now->format(Clock::FORMAT),
            'open_key' => $keepKey ? $delivery->open_key : null,
        ])) {
            Event::dispatch(new NotificationMailSent($delivery->refresh()));
        }
    }

    public static function finish(NotificationDelivery $delivery, DeliveryStatus $status, CancelReason $reason, DeliveryStatus $from = DeliveryStatus::Sending): void
    {
        $changed = self::transition($delivery, $from, [
            'status' => $status->value,
            'cancel_reason' => $reason->value,
            'open_key' => null,
        ]);

        if ($changed && $status === DeliveryStatus::Failed) {
            Event::dispatch(new NotificationMailFailed($delivery->refresh(), $reason));
        }
    }

    public static function hold(NotificationDelivery $delivery, CarbonImmutable $until): void
    {
        if (self::transition($delivery, DeliveryStatus::Sending, [
            'status' => DeliveryStatus::Held->value,
            'send_after' => $until->format(Clock::FORMAT),
        ])) {
            Event::dispatch(new NotificationMailHeld($delivery->refresh(), $until));
        }
    }

    public static function retry(NotificationDelivery $delivery, CarbonImmutable $at): void
    {
        self::transition($delivery, DeliveryStatus::Sending, [
            'status' => DeliveryStatus::Pending->value,
            'send_after' => $at->format(Clock::FORMAT),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function transition(NotificationDelivery $delivery, DeliveryStatus $from, array $values): bool
    {
        return NotificationModels::delivery()::query()
            ->whereKey($delivery->getKey())
            ->where('status', $from->value)
            ->update($values) > 0;
    }
}
