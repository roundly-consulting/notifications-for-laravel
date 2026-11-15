<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Actions\Sending\ResolveRecipientsAction;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Events\ReminderScheduled;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;

/**
 * Repeating reminder chains (`Reminder::every()`): one chain of rows per subject, rule and
 * recipient. Only one row of a chain is open at a time — it holds the chain's active key — and
 * firing it writes the next one, so fireDue() stays a plain due-time query. `level` counts the
 * reminders a chain has sent (the row's own number).
 *
 * @internal
 */
final class ReminderChains
{
    /**
     * Start a chain for every recipient that has none for the rule's current anchor. A recipient
     * whose chain is open, or already ran for this anchor (acknowledged, ended, cancelled), is left
     * alone — so scheduling again only adds the newcomers. A chain that ran for an EARLIER anchor
     * starts over when the rule re-arms.
     *
     * @param  list<Model>  $recipients
     * @return list<NotificationReminder>
     */
    public static function start(Model $subject, NotificationType $type, ReminderRule $rule, array $recipients, CarbonImmutable $now): array
    {
        $anchor = self::anchor($subject, $rule, $now);

        if ($anchor === null) {
            return [];
        }

        $started = [];

        foreach ($recipients as $recipient) {
            $row = self::startFor($subject, $type, $rule, $recipient, $anchor, $now);

            if ($row !== null) {
                $started[] = $row;
            }
        }

        return $started;
    }

    /**
     * Follow the rule's anchor: open rows re-time to the moved anchor (or cancel when it was
     * cleared), chains that ended re-arm when the anchor moved later, and a rule with its own
     * recipients starts chains for anyone who has none.
     */
    public static function sync(Model $subject, NotificationType $type, ReminderRule $rule, CarbonImmutable $now): int
    {
        $changed = 0;
        $attribute = $rule->anchorAttribute();

        if ($attribute !== null) {
            $anchor = Anchors::read($subject, $attribute);

            foreach (self::rows($subject, $type, $rule)->where('status', ReminderStatus::Pending->value)->lockForUpdate()->get() as $row) {
                $changed += self::retime($row, $rule, $subject, $anchor, $now);
            }

            if ($anchor !== null && $rule->rearms()) {
                $changed += count(self::start($subject, $type, $rule, self::knownRecipients($subject, $type, $rule), $now));
            }
        }

        $to = $rule->recipients();

        if ($to !== null) {
            $recipients = app(ResolveRecipientsAction::class)->execute($type, Recipients::from($to), $subject, null);
            $changed += count(self::start($subject, $type, $rule, $recipients, $now));
        }

        return $changed;
    }

    /**
     * Write the chain's next row after `$row` fired (`$level` = its number) or was skipped
     * (`$level` = the row's own). Null when the chain is done: `times()` reached, or the next
     * reminder would fall after `until()`.
     */
    public static function next(NotificationReminder $row, ReminderRule $rule, Model $subject, CarbonImmutable $now, int $level): ?NotificationReminder
    {
        $max = $rule->maxTimes();

        if (($max !== null && $level > $max) || $row->anchor_value === null) {
            return null;
        }

        $after = $row->due_at->greaterThan($now) ? $row->due_at : $now;
        $due = $rule->nextDue($row->anchor_value, $after);
        $end = $rule->endFor($subject);

        if ($end !== null && $due->greaterThan($end)) {
            return null;
        }

        $recipient = ModelRefs::find($row->recipient_type, $row->recipient_id, unscoped: true);

        if ($recipient === null) {
            return null;
        }

        return self::insert($subject, $row->type, $rule, $recipient, $row->anchor_value, $due, $level);
    }

    /**
     * Re-time an open row to a moved anchor — to the first slot of the new anchor still ahead —
     * or cancel it when the anchor was cleared or the chain would end before that slot.
     */
    public static function retime(NotificationReminder $row, ReminderRule $rule, Model $subject, ?CarbonImmutable $anchor, CarbonImmutable $now): int
    {
        if ($anchor === null) {
            ReminderRows::cancel($row, CancelReason::AnchorCleared);

            return 1;
        }

        if (Anchors::same($anchor, $row->anchor_value)) {
            return 0;
        }

        $due = $rule->nextDue($anchor, $now->subSecond());
        $end = $rule->endFor($subject);

        if ($end !== null && $due->greaterThan($end)) {
            ReminderRows::cancel($row, CancelReason::Passed);

            return 1;
        }

        ReminderRows::reschedule($row, $due, $anchor);

        return 1;
    }

    /**
     * The chain's anchor: the rule's date attribute, or now for a chain anchored to scheduling.
     */
    private static function anchor(Model $subject, ReminderRule $rule, CarbonImmutable $now): ?CarbonImmutable
    {
        $attribute = $rule->anchorAttribute();

        return $attribute === null ? $now : Anchors::read($subject, $attribute);
    }

    private static function startFor(Model $subject, NotificationType $type, ReminderRule $rule, Model $recipient, CarbonImmutable $anchor, CarbonImmutable $now): ?NotificationReminder
    {
        $rows = self::rows($subject, $type, $rule)
            ->where('recipient_type', $recipient->getMorphClass())
            ->where('recipient_id', $recipient->getKey())
            ->lockForUpdate()
            ->get();

        if ($rows->contains(static fn (NotificationReminder $row): bool => $row->status->isActive())) {
            return null;
        }

        if ($rows->isNotEmpty()) {
            // A chain anchored to scheduling runs once per recipient; an anchored one once per
            // anchor value, and again only when the anchor moved later and the rule re-arms.
            $latest = $rows->max(static fn (NotificationReminder $row): int => $row->anchor_value?->getTimestamp() ?? 0);

            if ($rule->anchorAttribute() === null || ! $rule->rearms() || $anchor->getTimestamp() <= $latest) {
                return null;
            }
        }

        // A slot exactly now still counts: a zero first delay sends at once.
        $due = $rule->nextDue($anchor, $now->subSecond());
        $end = $rule->endFor($subject);

        if ($end !== null && $due->greaterThan($end)) {
            return null;
        }

        return self::insert($subject, $type->key(), $rule, $recipient, $anchor, $due, 1);
    }

    private static function insert(Model $subject, string $type, ReminderRule $rule, Model $recipient, CarbonImmutable $anchor, CarbonImmutable $due, int $level): ?NotificationReminder
    {
        $class = NotificationModels::reminder();
        $row = new $class;
        $row->forceFill([
            'kind' => ReminderKind::Reminder,
            'type' => $type,
            'rule' => $rule->ruleKey(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'anchor' => $rule->anchorAttribute(),
            'offset' => null,
            'anchor_value' => $anchor,
            'due_at' => $due,
            'level' => $level,
            'recipients' => Recipients::users($recipient)->toStored(),
            'data' => ['reminder' => $rule->interval(), 'occurrence' => $level],
            'status' => ReminderStatus::Pending,
            'active_key' => self::key($subject, $type, $rule->ruleKey(), $recipient),
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
            'attempts' => 0,
        ]);

        try {
            $row->getConnection()->transaction(static fn (): bool => $row->save());
        } catch (UniqueConstraintViolationException) {
            // A concurrent schedule or fire wrote the chain's open row first.
            return null;
        }

        Event::dispatch(new ReminderScheduled($row));

        return $row;
    }

    /**
     * The open-row key one chain's rows share: one open row per subject, type, rule and recipient.
     */
    private static function key(Model $subject, string $type, string $rule, Model $recipient): string
    {
        return Keys::reminder(ModelRefs::identity($subject), $type, $rule, 'to:'.ModelRefs::identity($recipient));
    }

    /**
     * @return Builder<NotificationReminder>
     */
    private static function rows(Model $subject, NotificationType $type, ReminderRule $rule): Builder
    {
        return ReminderRows::forSubject($subject, $type->key())
            ->where('kind', ReminderKind::Reminder->value)
            ->where('rule', $rule->ruleKey())
            ->whereNotNull('recipient_type');
    }

    /**
     * Everyone the rule's chains on this subject were ever for.
     *
     * @return list<Model>
     */
    private static function knownRecipients(Model $subject, NotificationType $type, ReminderRule $rule): array
    {
        $recipients = [];

        foreach (self::rows($subject, $type, $rule)->get(['recipient_type', 'recipient_id']) as $row) {
            $identity = $row->recipient_type.':'.(is_scalar($row->recipient_id) ? (string) $row->recipient_id : '');

            if (isset($recipients[$identity])) {
                continue;
            }

            $recipient = ModelRefs::find($row->recipient_type, $row->recipient_id);

            if ($recipient !== null) {
                $recipients[$identity] = $recipient;
            }
        }

        return array_values($recipients);
    }
}
