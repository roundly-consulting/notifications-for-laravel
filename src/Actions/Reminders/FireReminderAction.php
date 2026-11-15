<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Actions\Sending\SendNotificationAction;
use RoundlyConsulting\Notifications\DataTransferObjects\ReminderReference;
use RoundlyConsulting\Notifications\DataTransferObjects\SendNotificationData;
use RoundlyConsulting\Notifications\DataTransferObjects\SendResult;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Events\ReminderFired;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;
use RoundlyConsulting\Notifications\Support\Anchors;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\Escalations;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\ReminderChains;
use RoundlyConsulting\Notifications\Support\ReminderClaims;
use RoundlyConsulting\Notifications\Support\ReminderRows;
use RoundlyConsulting\Notifications\Support\Subjects;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use Throwable;

/**
 * Fires one due reminder, claimed by compare-and-set. It re-reads everything first: the type and
 * rule, the subject, the anchor (a date changed behind the model's back reschedules instead of
 * firing), the rule's condition and the lateness bound. The send carries the idempotency key
 * `reminder:{id}`, so a re-fire after a crash sends nothing twice; access is checked per recipient.
 * The send goes through the manager, so `Notifications::fake()` records it.
 *
 * A repeating row (`Reminder::every()`) writes its chain's next row once it went out — unless its
 * recipient acknowledged meanwhile, `times()` is reached or the next one falls after `until()` —
 * and arms the rule's escalations once per chain. A late repeating row is skipped, not the chain.
 *
 * @internal
 */
final readonly class FireReminderAction
{
    public function __construct(
        private TypeRegistry $types,
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

        if ($type === null) {
            ReminderRows::cancel($row, CancelReason::UnknownType);

            return false;
        }

        $adHoc = ReminderRows::isAdHoc($row->rule);
        $rule = $adHoc ? null : ReminderRows::rule($type, $row->rule);

        if (! $adHoc && $rule === null) {
            ReminderRows::cancel($row, CancelReason::UnknownRule);

            return false;
        }

        $subject = Subjects::find($type, $row->subject_type, $row->subject_id);

        if ($subject === null) {
            ReminderRows::cancel($row, CancelReason::SubjectDeleted);

            return false;
        }

        if ($rule !== null && $rule->repeats()) {
            return $this->fireRepeating($row, $rule, $type, $subject, $now);
        }

        if ($row->anchor !== null && $row->offset !== null) {
            $anchor = Anchors::read($subject, $row->anchor);

            if ($anchor === null) {
                ReminderRows::cancel($row, CancelReason::AnchorCleared);

                return false;
            }

            if (! Anchors::same($anchor, $row->anchor_value)) {
                $due = ReminderRule::dueAt($anchor, $row->offset);

                $due->greaterThan($now)
                    ? ReminderRows::reschedule($row, $due, $anchor)
                    : ReminderRows::cancel($row, CancelReason::Passed);

                return false;
            }
        }

        if ($rule !== null && ! $rule->holds($subject)) {
            ReminderRows::cancel($row, CancelReason::Condition);

            return false;
        }

        $late = $now->greaterThan($row->due_at->add(NotificationsConfig::maxLateness()));

        if ($late && ($row->anchor_value === null || $row->anchor_value->lessThanOrEqualTo($now))) {
            ReminderRows::cancel($row, CancelReason::Late, ReminderStatus::Skipped);

            return false;
        }

        $recipients = $row->recipients !== null
            ? RecipientSpec::fromStored($row->recipients)
            : Recipients::from($rule?->recipients() ?? Recipients::users());

        $id = ModelRefs::key($row);

        self::deliver(new SendNotificationData(
            type: $type->key(),
            // A type without a subject class reminds about nothing: the row's subject only anchors
            // and owns the reminder (an ad-hoc reminder on any model).
            subject: $type->subject() === null ? null : $subject,
            recipients: $recipients,
            data: $row->data ?? ['reminder' => $row->offset],
            idempotencyKey: 'reminder:'.$id,
            reminderId: $id,
            reminder: new ReminderReference($id, $row->rule, $row->offset, $row->due_at),
        ));

        $row->forceFill([
            'status' => ReminderStatus::Sent,
            'fired_at' => $now,
            'active_key' => null,
            'claimed_at' => null,
        ])->save();

        if ($rule !== null) {
            Escalations::armForReminder($row, $rule->escalations(), $subject, $now);
        }

        Event::dispatch(new ReminderFired($row));

        return true;
    }

    private function fireRepeating(NotificationReminder $row, ReminderRule $rule, NotificationType $type, Model $subject, CarbonImmutable $now): bool
    {
        $attribute = $rule->anchorAttribute();

        if ($attribute !== null) {
            $anchor = Anchors::read($subject, $attribute);

            // The date changed behind the model's back: follow it instead of firing.
            if ($anchor === null || ! Anchors::same($anchor, $row->anchor_value)) {
                if (ReminderChains::retime($row, $rule, $subject, $anchor, $now) === 0) {
                    $row->forceFill(['status' => ReminderStatus::Pending, 'claimed_at' => null])->save();
                }

                return false;
            }
        }

        if (! $rule->holds($subject)) {
            ReminderRows::cancel($row, CancelReason::Condition);

            return false;
        }

        $end = $rule->endFor($subject);

        if ($end !== null && $row->due_at->greaterThan($end)) {
            ReminderRows::cancel($row, CancelReason::Passed);

            return false;
        }

        // Too late to be useful: skip this one, keep the chain.
        if ($now->greaterThan($row->due_at->add(NotificationsConfig::maxLateness()))) {
            $row->getConnection()->transaction(static function () use ($row, $rule, $subject, $now): void {
                ReminderRows::cancel($row, CancelReason::Late, ReminderStatus::Skipped);
                ReminderChains::next($row, $rule, $subject, $now, $row->level);
            });

            return false;
        }

        $id = ModelRefs::key($row);

        self::deliver(new SendNotificationData(
            type: $type->key(),
            subject: $subject,
            recipients: RecipientSpec::fromStored($row->recipients ?? []),
            data: $row->data ?? ['reminder' => $rule->interval(), 'occurrence' => $row->level],
            idempotencyKey: 'reminder:'.$id,
            reminderId: $id,
            reminder: new ReminderReference($id, $row->rule, $row->offset, $row->due_at),
        ));

        $row->getConnection()->transaction(static function () use ($row, $rule, $subject, $now): void {
            // An acknowledgement that landed while this one was sending stamped the row: it is
            // sent, but the chain ends here. Locked, so a later acknowledgement waits for this
            // commit and then cancels the next row.
            $acknowledged = NotificationModels::reminder()::query()->whereKey($row->getKey())->lockForUpdate()->value('acknowledged_at') !== null;

            $row->forceFill(['status' => ReminderStatus::Sent, 'fired_at' => $now, 'active_key' => null, 'claimed_at' => null])->save();

            if ($acknowledged) {
                return;
            }

            ReminderChains::next($row, $rule, $subject, $now, $row->level + 1);

            foreach ($rule->escalations() as $index => $level) {
                // Each level once per chain: a delay counts from the chain's first reminder, a
                // count arms on that reminder.
                if (($level->firings ?? 1) === $row->level) {
                    Escalations::armLevel($row, $level, $index + 1, $subject, $now);
                }
            }
        });

        Event::dispatch(new ReminderFired($row));

        return true;
    }

    /**
     * Sends through the manager, so a fake records a reminder's send like any other.
     */
    private static function deliver(SendNotificationData $data): void
    {
        app(NotificationsManager::class)->perform(
            NotificationOperation::Send,
            SendNotificationAction::class,
            static fn (SendNotificationAction $action): SendResult => $action->execute($data),
            ['data' => $data],
        );
    }
}
