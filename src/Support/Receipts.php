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

    /** Ids per query: a bounded number of placeholders on every engine. */
    private const int CHUNK = 1000;

    /**
     * Whether this key already reached every one of these recipients (a replay). One query per
     * morph type and 1 000 recipients, after one cheap check that the key reached anyone at all.
     *
     * @param  list<Model>  $recipients  the fan-out batch (never empty)
     */
    public static function reachedAll(string $idempotencyKey, array $recipients): bool
    {
        $class = NotificationModels::receipt();
        $key = Keys::receipt($idempotencyKey);

        if (! $class::query()->where('key', $key)->exists()) {
            return false;
        }

        $byType = [];

        foreach ($recipients as $recipient) {
            $byType[$recipient->getMorphClass()][] = ModelRefs::key($recipient);
        }

        foreach ($byType as $type => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
                $reached = $class::query()
                    ->where('key', $key)
                    ->where('recipient_type', $type)
                    ->whereIn('recipient_id', $chunk)
                    ->count();

                if ($reached < count($chunk)) {
                    return false;
                }
            }
        }

        return true;
    }
}
