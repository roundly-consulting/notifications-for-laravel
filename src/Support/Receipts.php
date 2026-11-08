<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The idempotency ledger. A claim is one `INSERT … ON CONFLICT DO NOTHING` (INSERT IGNORE on
 * MySQL): never a caught unique violation, which would abort a PostgreSQL transaction.
 *
 * @internal
 */
final class Receipts
{
    /**
     * True when this key had not reached this recipient yet (and now has).
     */
    public static function claim(string $idempotencyKey, Model $recipient): bool
    {
        $class = NotificationModels::receipt();
        $model = new $class;

        $attributes = [
            'key' => Keys::receipt($idempotencyKey),
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
            'created_at' => Clock::now()->format(Clock::FORMAT),
        ];

        if ($model->usesUniqueIds()) {
            $attributes['id'] = $model->newUniqueId();
        }

        return $class::query()->insertOrIgnore($attributes) > 0;
    }

    /**
     * Whether this key already reached every one of these recipients (a replay).
     *
     * @param  list<Model>  $recipients
     */
    public static function reachedAll(string $idempotencyKey, array $recipients): bool
    {
        if ($recipients === []) {
            return false;
        }

        $reached = NotificationModels::receipt()::query()
            ->where('key', Keys::receipt($idempotencyKey))
            ->where(static function ($query) use ($recipients): void {
                foreach ($recipients as $recipient) {
                    $query->orWhere(static fn ($pair) => $pair->where('recipient_type', $recipient->getMorphClass())->where('recipient_id', $recipient->getKey()));
                }
            })
            ->count();

        return $reached >= count($recipients);
    }
}
