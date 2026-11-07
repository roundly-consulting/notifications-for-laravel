<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Actions\Sending\SendNotificationAction;
use RoundlyConsulting\Notifications\DataTransferObjects\ReminderReference;
use RoundlyConsulting\Notifications\DataTransferObjects\SendNotificationData;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Events\EscalationFired;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Reminders\EscalationRule;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\Durations;
use RoundlyConsulting\Notifications\Support\Escalations;
use RoundlyConsulting\Notifications\Support\Keys;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\ReminderClaims;
use RoundlyConsulting\Notifications\Support\ReminderRows;
use RoundlyConsulting\Notifications\Support\Subjects;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use Throwable;

/**
 * Fires one due escalation level: unless the subject was acknowledged, its condition failed or it
 * is gone, notify the level's recipients (in their own lane, never folded into the original send)
 * and arm the next level.
 *
 * @internal
 */
final readonly class FireEscalationAction
{
    public function __construct(
        private TypeRegistry $types,
        private SendNotificationAction $send,
    ) {}

    public function execute(int|string $id): bool
    {
        if (! ReminderClaims::claim($id)) {
            return false;
        }

        $row = NotificationModels::reminder()::query()->find($id);

        if ($row === null) {
            return false;
        }

        try {
            return $this->fire($row);
        } catch (Throwable $exception) {
            report($exception);

            $row->attempts >= 3
                ? ReminderRows::cancel($row, CancelReason::Unknown)
                : $row->forceFill(['status' => ReminderStatus::Pending, 'claimed_at' => null])->save();

            return false;
        }
    }

    private function fire(NotificationReminder $row): bool
    {
        $now = Clock::now();
        $type = $this->types->get($row->type);
        $root = $row->parent_id === null ? null : NotificationModels::reminder()::query()->withTrashed()->find($row->parent_id);

        if ($type === null) {
            ReminderRows::cancel($row, CancelReason::UnknownType);

            return false;
        }

        if ($row->acknowledged_at !== null || $root?->acknowledged_at !== null) {
            ReminderRows::cancel($row, CancelReason::Acknowledged);

            return false;
        }

        $subject = Subjects::find($type, $row->subject_type, $row->subject_id);

        if ($subject === null) {
            ReminderRows::cancel($row, CancelReason::SubjectDeleted);

            return false;
        }

        $levels = $this->levels($row, $root, $type->reminders());

        if ($levels === null) {
            ReminderRows::cancel($row, CancelReason::UnknownRule);

            return false;
        }

        $rule = $root === null ? null : ReminderRows::rule($type, $root->rule);

        if ($rule !== null && ! $rule->holds($subject)) {
            ReminderRows::cancel($row, CancelReason::Condition);

            return false;
        }

        $level = $levels[$row->level - 1] ?? null;

        if ($level === null) {
            ReminderRows::cancel($row, CancelReason::UnknownRule);

            return false;
        }

        $escalationType = $level->type ?? $type->key();

        if (! $this->types->has($escalationType)) {
            ReminderRows::cancel($row, CancelReason::UnknownType);

            return false;
        }

        $recipients = $row->recipients !== null
            ? RecipientSpec::fromStored($row->recipients)
            : Recipients::from($level->to);

        $data = $row->data ?? [];
        $payload = $root === null ? (is_array($data['payload'] ?? null) ? $data['payload'] : []) : $data;
        $named = [];

        foreach ($payload as $key => $value) {
            $named[(string) $key] = $value;
        }

        $id = ModelRefs::key($row);

        $this->send->execute(new SendNotificationData(
            type: $escalationType,
            subject: $subject,
            recipients: $recipients,
            data: $named,
            idempotencyKey: 'escalation:'.$id,
            escalationLevel: $row->level,
            reminderId: $root === null ? $id : ModelRefs::key($root),
            reminder: new ReminderReference($id, $row->rule, $root?->offset, $row->due_at, $row->level),
        ));

        $row->forceFill(['status' => ReminderStatus::Sent, 'fired_at' => $now, 'active_key' => null, 'claimed_at' => null])->save();

        $next = $levels[$row->level] ?? null;

        if ($next !== null) {
            $root === null
                ? $this->armNextSendLevel($row, $next, $now)
                : Escalations::armLevel($root, $next, $row->level + 1, $subject, $now);
        }

        Event::dispatch(new EscalationFired($row));

        return true;
    }

    /**
     * @param  list<ReminderRule>  $rules
     * @return list<EscalationRule>|null
     */
    private function levels(NotificationReminder $row, ?NotificationReminder $root, array $rules): ?array
    {
        if ($root !== null) {
            foreach ($rules as $rule) {
                if ($rule->ruleKey() === $root->rule) {
                    return $rule->escalations();
                }
            }

            return null;
        }

        $chain = $row->data['chain'] ?? null;

        if (! is_array($chain)) {
            return null;
        }

        $levels = [];

        foreach ($chain as $level) {
            if (! is_array($level) || ! is_string($level['after'] ?? null) || ! is_array($level['to'] ?? null)) {
                return null;
            }

            $type = $level['type'] ?? null;

            $levels[] = new EscalationRule(
                Durations::iso($level['after']) ?? CarbonInterval::seconds(0),
                RecipientSpec::fromStored($level['to']),
                is_string($type) ? $type : null,
            );
        }

        return $levels;
    }

    private function armNextSendLevel(NotificationReminder $row, EscalationRule $next, CarbonImmutable $now): void
    {
        $subject = ModelRefs::find($row->subject_type, $row->subject_id, withTrashed: true);
        $send = $row->data['send'] ?? null;

        if ($subject === null || ! is_string($send)) {
            return;
        }

        $level = $row->level + 1;

        Escalations::insert($subject, [
            'type' => $row->type,
            'rule' => null,
            'parent_id' => null,
            'level' => $level,
            'due_at' => $now->add($next->after),
            'recipients' => Escalations::storedRecipients($next->to),
            'data' => $row->data,
            'active_key' => Keys::reminder(ModelRefs::identity($subject), $row->type, $send, 'L'.$level),
        ]);
    }
}
