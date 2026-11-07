<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Events\ReminderRescheduled;
use RoundlyConsulting\Notifications\Events\ReminderScheduled;
use RoundlyConsulting\Notifications\Models\NotificationReminder;

/**
 * Writes a reminder row by its active key: a pending row with the key is replaced (re-timed,
 * new recipients and data), otherwise a new one is inserted under a savepoint.
 *
 * @internal
 */
final class ReminderWriter
{
    /**
     * @param  array<string, mixed>|null  $recipients
     * @param  array<string, mixed>|null  $data
     * @param  bool  $keyedByOffset  rule rows: one per offset; ad-hoc rows: one per key (a second
     *                               remind() with the same key replaces the pending row)
     */
    public static function upsert(
        Model $subject,
        string $type,
        string $rule,
        ?string $anchor,
        ?string $offset,
        ?CarbonImmutable $anchorValue,
        CarbonImmutable $due,
        ?array $recipients,
        ?array $data,
        bool $keyedByOffset = true,
    ): NotificationReminder {
        $class = NotificationModels::reminder();
        $key = Keys::reminder(ModelRefs::identity($subject), $type, $rule, $keyedByOffset ? $offset : null);

        return $class::query()->getConnection()->transaction(static function () use ($class, $key, $subject, $type, $rule, $anchor, $offset, $anchorValue, $due, $recipients, $data): NotificationReminder {
            for ($attempt = 1; ; $attempt++) {
                $existing = $class::query()->where('active_key', $key)->lockForUpdate()->first();

                if ($existing !== null && $existing->status === ReminderStatus::Pending) {
                    $existing->forceFill([
                        'anchor' => $anchor,
                        'offset' => $offset,
                        'due_at' => $due,
                        'anchor_value' => $anchorValue,
                        'recipients' => $recipients,
                        'data' => $data,
                    ])->save();

                    Event::dispatch(new ReminderRescheduled($existing));

                    return $existing;
                }

                $row = new $class;
                $row->forceFill([
                    'kind' => ReminderKind::Reminder,
                    'type' => $type,
                    'rule' => $rule,
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => $subject->getKey(),
                    'anchor' => $anchor,
                    'offset' => $offset,
                    'anchor_value' => $anchorValue,
                    'due_at' => $due,
                    'level' => 0,
                    'recipients' => $recipients,
                    'data' => $data,
                    'status' => ReminderStatus::Pending,
                    // A firing row keeps the key until it closes; the new one waits for it.
                    'active_key' => $existing === null ? $key : null,
                    'attempts' => 0,
                ]);

                try {
                    $row->getConnection()->transaction(static fn (): bool => $row->save());
                } catch (UniqueConstraintViolationException $exception) {
                    if ($attempt >= 3) {
                        throw $exception;
                    }

                    continue;
                }

                Event::dispatch(new ReminderScheduled($row));

                return $row;
            }
        });
    }
}
