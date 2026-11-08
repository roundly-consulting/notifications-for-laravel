<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Actions\Sending\SendNotificationAction;
use RoundlyConsulting\Notifications\DataTransferObjects\ReminderReference;
use RoundlyConsulting\Notifications\DataTransferObjects\SendNotificationData;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Events\ReminderFired;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;
use RoundlyConsulting\Notifications\Support\Anchors;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\Escalations;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
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
 *
 * @internal
 */
final readonly class FireReminderAction
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

        $this->send->execute(new SendNotificationData(
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
}
