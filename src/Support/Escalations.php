<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\DataTransferObjects\SendNotificationData;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Exceptions\UnstorableRecipientsException;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Reminders\EscalationRule;

/**
 * Escalation rows: "if not acknowledged by X, notify Y". A reminder rule's levels are re-read
 * from the type when they fire (they may hold closures); a send's levels are stored with the row.
 *
 * @internal
 */
final class Escalations
{
    /**
     * @return array<string, mixed>
     *
     * @throws UnstorableRecipientsException
     */
    public static function storedRecipients(RecipientSpec|Closure $to): array
    {
        if ($to instanceof Closure) {
            throw UnstorableRecipientsException::closure();
        }

        return $to->toStored();
    }

    /**
     * Arm the first level of a send's escalation chain.
     */
    public static function armForSend(NotificationType $type, Model $subject, SendNotificationData $data, CarbonImmutable $at): void
    {
        $chain = array_map(static fn (EscalationRule $rule): array => [
            'after' => Durations::toIso($rule->after),
            'to' => self::storedRecipients($rule->to),
            'type' => $rule->type,
        ], $data->escalations);

        $send = 'send:'.($data->idempotencyKey ?? (string) Str::ulid());

        self::insert($subject, [
            'type' => $type->key(),
            'rule' => null,
            'parent_id' => null,
            'level' => 1,
            'due_at' => $at->add($data->escalations[0]->after),
            'recipients' => $chain[0]['to'],
            'data' => ['payload' => $data->data, 'chain' => $chain, 'send' => $send],
            'active_key' => Keys::reminder(ModelRefs::identity($subject), $type->key(), $send, 'L1'),
        ]);
    }

    /**
     * Arm the first level of a fired reminder's escalation chain.
     *
     * @param  list<EscalationRule>  $levels
     */
    public static function armForReminder(NotificationReminder $reminder, array $levels, Model $subject, CarbonImmutable $firedAt): void
    {
        if ($levels === []) {
            return;
        }

        self::armLevel($reminder, $levels[0], 1, $subject, $firedAt);
    }

    public static function armLevel(NotificationReminder $root, EscalationRule $level, int $index, Model $subject, CarbonImmutable $from): void
    {
        $stored = $level->to instanceof RecipientSpec && $level->to->storable() ? $level->to->toStored() : null;

        self::insert($subject, [
            'type' => $root->type,
            'rule' => $root->rule,
            'parent_id' => $root->getKey(),
            'level' => $index,
            'due_at' => $from->add($level->after),
            'recipients' => $stored,
            'data' => $root->data,
            'active_key' => Keys::escalation((string) self::scalar($root->getKey()), $index),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function insert(Model $subject, array $attributes): ?NotificationReminder
    {
        $class = NotificationModels::reminder();
        $row = new $class;
        $row->forceFill($attributes + [
            'kind' => ReminderKind::Escalation,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'status' => ReminderStatus::Pending,
            'attempts' => 0,
        ]);

        try {
            $row->getConnection()->transaction(static fn (): bool => $row->save());
        } catch (UniqueConstraintViolationException) {
            // Already armed by an earlier attempt of the same fire.
            return null;
        }

        return $row;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
