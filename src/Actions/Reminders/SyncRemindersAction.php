<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Events\ReminderScheduled;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;
use RoundlyConsulting\Notifications\Support\Anchors;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\Keys;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\ReminderRows;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * Brings a subject's reminder rows in line with its dates: pending rows follow their anchor
 * (moved, or cancelled when the new time has passed — skipped offsets never fire at once), rules
 * with recipients get their future offsets scheduled, sent reminders re-arm when the anchor moves
 * later (a renewal), and a cleared anchor cancels. Firing rows are left to the fire step, which
 * re-reads the anchor. A lost race is absorbed: sync never throws on a conflict.
 */
final readonly class SyncRemindersAction
{
    public function __construct(
        private TypeRegistry $types,
    ) {}

    /**
     * @return int rows scheduled, moved or cancelled
     */
    public function execute(Model $subject, ?string $type = null): int
    {
        $types = $type === null ? $this->withAdHocTypes($subject, $this->types->forSubject($subject)) : [$this->types->find($type)];
        $changed = 0;

        foreach ($types as $definition) {
            $changed += (int) NotificationModels::reminder()::query()->getConnection()
                ->transaction(fn (): int => $this->syncType($subject, $definition));
        }

        return $changed;
    }

    /**
     * Adds the types of the subject's pending anchored ad-hoc rows — a type without a subject
     * class takes ad-hoc reminders on any model, and those follow their anchor too.
     *
     * @param  list<NotificationType>  $types
     * @return list<NotificationType>
     */
    private function withAdHocTypes(Model $subject, array $types): array
    {
        $keys = ReminderRows::forSubject($subject)
            ->where('kind', ReminderKind::Reminder->value)
            ->where('status', ReminderStatus::Pending->value)
            ->where('rule', 'like', 'adhoc:%')
            ->whereNotNull('anchor')
            ->distinct()
            ->pluck('type')
            ->all();

        $known = array_map(static fn (NotificationType $type): string => $type->key(), $types);

        foreach ($keys as $key) {
            $type = is_string($key) && ! in_array($key, $known, true) ? $this->types->get($key) : null;

            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }

    private function syncType(Model $subject, NotificationType $type): int
    {
        $now = Clock::now();
        $changed = 0;
        $active = ReminderRows::forSubject($subject, $type->key())
            ->where('kind', ReminderKind::Reminder->value)
            ->whereIn('status', [ReminderStatus::Pending->value, ReminderStatus::Firing->value])
            ->lockForUpdate()
            ->get();

        foreach ($type->reminders() as $rule) {
            if ($rule->attribute === null) {
                continue;
            }

            $anchor = Anchors::read($subject, $rule->attribute);

            foreach ($rule->signedOffsets() as $offset) {
                if ($offset === null) {
                    continue;
                }

                $rows = $active->filter(static fn (NotificationReminder $row): bool => $row->rule === $rule->ruleKey() && $row->offset === $offset);
                $changed += $this->syncOffset($subject, $type, $rule, $offset, $anchor, $rows, $now);
            }
        }

        // Ad-hoc anchored rows follow their anchor like rule rows (never inserted here).
        foreach ($active as $row) {
            if (! ReminderRows::isAdHoc($row->rule) || $row->anchor === null || $row->offset === null || $row->status !== ReminderStatus::Pending) {
                continue;
            }

            $changed += $this->retime($row, Anchors::read($subject, $row->anchor), $row->offset, $now);
        }

        return $changed;
    }

    /**
     * @param  Collection<int, NotificationReminder>  $rows
     */
    private function syncOffset(Model $subject, NotificationType $type, ReminderRule $rule, string $offset, ?CarbonImmutable $anchor, Collection $rows, CarbonImmutable $now): int
    {
        $pending = $rows->first(static fn (NotificationReminder $row): bool => $row->status === ReminderStatus::Pending);
        $firing = $rows->first(static fn (NotificationReminder $row): bool => $row->status === ReminderStatus::Firing);

        if ($firing !== null) {
            return 0;
        }

        if ($pending !== null) {
            return $this->retime($pending, $anchor, $offset, $now);
        }

        if ($anchor === null || $rule->recipients() === null) {
            return 0;
        }

        $due = ReminderRule::dueAt($anchor, $offset);

        if ($due->lessThanOrEqualTo($now)) {
            return 0;
        }

        $terminal = ReminderRows::forSubject($subject, $type->key())
            ->where('kind', ReminderKind::Reminder->value)
            ->where('rule', $rule->ruleKey())
            ->where('offset', $offset)
            ->whereNotIn('status', [ReminderStatus::Pending->value, ReminderStatus::Firing->value])
            ->orderByDesc('id')
            ->get();

        foreach ($terminal as $row) {
            // A row cancelled because its time had passed never ran for this date (it kept the
            // anchor it was scheduled for): moving the date back schedules it again.
            if (Anchors::same($row->anchor_value, $anchor)
                && ! in_array($row->cancel_reason, [CancelReason::SubjectDeleted, CancelReason::AnchorCleared, CancelReason::Passed], true)) {
                // Already sent, skipped or cancelled for this very date: no churn per save.
                return 0;
            }
        }

        $sent = $terminal->contains(static fn (NotificationReminder $row): bool => $row->status === ReminderStatus::Sent);

        if ($sent && ! $rule->rearms()) {
            return 0;
        }

        return $this->insert($subject, $type, $rule, $offset, $anchor, $due) ? 1 : 0;
    }

    private function retime(NotificationReminder $row, ?CarbonImmutable $anchor, string $offset, CarbonImmutable $now): int
    {
        if ($anchor === null) {
            ReminderRows::cancel($row, CancelReason::AnchorCleared);

            return 1;
        }

        $due = ReminderRule::dueAt($anchor, $offset);

        if (Anchors::same($due, $row->due_at)) {
            return 0;
        }

        if ($due->lessThanOrEqualTo($now)) {
            // Moving the anchor earlier never fires the offsets it skipped, all at once.
            ReminderRows::cancel($row, CancelReason::Passed);

            return 1;
        }

        ReminderRows::reschedule($row, $due, $anchor);

        return 1;
    }

    private function insert(Model $subject, NotificationType $type, ReminderRule $rule, string $offset, CarbonImmutable $anchor, CarbonImmutable $due): bool
    {
        $class = NotificationModels::reminder();
        $row = new $class;
        $row->forceFill([
            'kind' => ReminderKind::Reminder,
            'type' => $type->key(),
            'rule' => $rule->ruleKey(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'anchor' => $rule->attribute,
            'offset' => $offset,
            'anchor_value' => $anchor,
            'due_at' => $due,
            'level' => 0,
            'status' => ReminderStatus::Pending,
            'active_key' => Keys::reminder(ModelRefs::identity($subject), $type->key(), $rule->ruleKey(), $offset),
            'attempts' => 0,
        ]);

        try {
            $row->getConnection()->transaction(static fn (): bool => $row->save());
        } catch (UniqueConstraintViolationException) {
            // A concurrent sync scheduled it first: treat as synced.
            return false;
        }

        Event::dispatch(new ReminderScheduled($row));

        return true;
    }
}
