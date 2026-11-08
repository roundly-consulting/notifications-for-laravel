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

        return $class::query()->getConnection()->transaction(static function () use ($class, $key, $subject, $type, $rule, $anchor, $offset, $anchorValue, $due, $recipients, $data, $keyedByOffset): NotificationReminder {
            for ($attempt = 1; ; $attempt++) {
                $existing = $class::query()->where('active_key', $key)->lockForUpdate()->first();
                $values = [
                    'anchor' => $anchor,
                    'offset' => $offset,
                    'due_at' => $due,
                    'anchor_value' => $anchorValue,
                    'recipients' => $recipients,
                    'data' => $data,
                ];

                if ($existing !== null && $existing->status === ReminderStatus::Pending) {
                    $existing->forceFill($values)->save();

                    Event::dispatch(new ReminderRescheduled($existing));

                    return $existing;
                }

                // A row written while the keyed one was firing waits without the key. Replace it
                // instead of adding another, and give it the key once the firing row has closed,
                // so the reminder never exists (and fires) twice.
                $waiting = ReminderRows::forSubject($subject, $type)
                    ->where('kind', ReminderKind::Reminder->value)
                    ->where('rule', $rule)
                    ->when($keyedByOffset, static fn ($query) => $query->where('offset', $offset))
                    ->where('status', ReminderStatus::Pending->value)
                    ->whereNull('active_key')
                    ->lockForUpdate()
                    ->first();

                if ($waiting !== null) {
                    try {
                        $waiting->getConnection()->transaction(static fn (): bool => $waiting->forceFill($values + ['active_key' => $existing === null ? $key : null])->save());
                    } catch (UniqueConstraintViolationException $exception) {
                        if ($attempt >= 3) {
                            throw $exception;
                        }

                        continue;
                    }

                    Event::dispatch(new ReminderRescheduled($waiting));

                    return $waiting;
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
