<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\DataTransferObjects\ContextReference;
use RoundlyConsulting\Notifications\DataTransferObjects\ReminderReference;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\NotificationPayload;

/**
 * Rebuilds the payload a delivery row stands for: its data (minus the package's own `_notifications`
 * meta), actor, context, count, escalation and reminder.
 *
 * @internal
 */
final class DeliveryPayloads
{
    public const string META = '_notifications';

    public static function payload(NotificationDelivery $delivery, Model $recipient, ?Model $subject): NotificationPayload
    {
        $data = $delivery->data;
        $meta = is_array($data[self::META] ?? null) ? $data[self::META] : [];
        unset($data[self::META]);

        $context = $delivery->context_key === null
            ? null
            : new ContextReference($delivery->context_key, $delivery->context_label ?? $delivery->context_key);

        $subjectKey = $delivery->subject_id;

        return new NotificationPayload(
            type: $delivery->type,
            recipient: $recipient,
            subject: $subject,
            subjectKey: $subjectKey,
            actor: ModelRefs::find($delivery->actor_type, $delivery->actor_id),
            context: $context,
            data: $data,
            count: $delivery->events_count,
            firstAt: $delivery->first_event_at,
            lastAt: $delivery->last_event_at,
            channel: Channel::Mail,
            escalated: self::escalated($meta),
            reminder: self::reminderOf($meta),
        );
    }

    /**
     * The send's data, plus what a later render needs to know about its escalation and reminder
     * (under `_notifications`, which every reader strips) — on deliveries and inbox items alike.
     *
     * @return array<string, mixed>
     */
    public static function stored(PreparedSend $send): array
    {
        $meta = [];

        if ($send->escalationLevel > 0) {
            $meta['escalation'] = $send->escalationLevel;
        }

        if ($send->reminder !== null) {
            $meta['reminder'] = [
                'id' => $send->reminder->id,
                'rule' => $send->reminder->rule,
                'offset' => $send->reminder->offset,
                'due_at' => $send->reminder->dueAt->format(CarbonImmutable::ATOM),
                'level' => $send->reminder->level,
            ];
        }

        if ($meta === []) {
            return $send->data;
        }

        $data = $send->data;
        $data[self::META] = (is_array($data[self::META] ?? null) ? $data[self::META] : []) + $meta;

        return $data;
    }

    /**
     * Whether stored metadata marks an escalation.
     *
     * @param  array<array-key, mixed>  $meta
     */
    public static function escalated(array $meta): bool
    {
        $escalation = $meta['escalation'] ?? 0;

        return is_int($escalation) && $escalation > 0;
    }

    /**
     * @param  array<array-key, mixed>  $meta
     */
    public static function reminderOf(array $meta): ?ReminderReference
    {
        return is_array($meta['reminder'] ?? null) ? self::reminder($meta['reminder']) : null;
    }

    /**
     * @param  array<array-key, mixed>  $stored
     */
    private static function reminder(array $stored): ?ReminderReference
    {
        $id = $stored['id'] ?? null;
        $due = $stored['due_at'] ?? null;

        if ((! is_int($id) && ! is_string($id)) || ! is_string($due)) {
            return null;
        }

        $rule = $stored['rule'] ?? null;
        $offset = $stored['offset'] ?? null;
        $level = $stored['level'] ?? 0;

        return new ReminderReference(
            id: $id,
            rule: is_string($rule) ? $rule : null,
            offset: is_string($offset) ? $offset : null,
            dueAt: CarbonImmutable::parse($due)->utc(),
            level: is_int($level) ? $level : 0,
        );
    }
}
