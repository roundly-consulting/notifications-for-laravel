<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Actions\Sending\ResolveRecipientsAction;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationSubjectException;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;
use RoundlyConsulting\Notifications\Support\Anchors;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\ReminderChains;
use RoundlyConsulting\Notifications\Support\ReminderWriter;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * Schedules a type's reminder rules for one subject with explicit (stored) recipients — for rules
 * written without `->to()`. Future offsets only; a pending row of the same rule is replaced.
 *
 * A repeating rule (`Reminder::every()`) starts one chain per recipient instead: a recipient whose
 * chain is running or already ran for the current anchor keeps it, so calling this again with a
 * longer list only adds the newcomers. `$restart` starts a fresh chain (level 1, at the anchor's
 * next slot) for every recipient whose chain ended — acknowledged, `while()` false, cancelled,
 * `times()` / `until()` reached — even for the same anchor; an open chain is still kept. One-off
 * rules are scheduled as without it.
 */
final readonly class ScheduleRemindersAction
{
    public function __construct(
        private TypeRegistry $types,
        private ResolveRecipientsAction $resolve,
    ) {}

    /**
     * @return list<NotificationReminder>
     */
    public function execute(Model $subject, string $type, RecipientSpec $to, bool $restart = false): array
    {
        $definition = $this->types->find($type);
        $type = $definition->key();     // a class-string is stored as its key
        $expected = $definition->subject();

        if ($expected === null || ! $subject instanceof $expected) {
            throw InvalidNotificationSubjectException::wrongClass($type, $expected === null ? '-' : class_basename($expected), class_basename($subject));
        }

        $stored = $to->toStored();
        $now = Clock::now();
        $rows = [];

        foreach ($definition->reminders() as $rule) {
            if ($rule->repeats()) {
                $recipients = $this->resolve->execute($definition, $to, $subject, null);
                $chains = NotificationModels::reminder()::query()->getConnection()
                    ->transaction(static fn (): array => ReminderChains::start($subject, $definition, $rule, $recipients, $now, $restart));
                array_push($rows, ...$chains);

                continue;
            }

            if ($rule->attribute === null) {
                continue;
            }

            $anchor = Anchors::read($subject, $rule->attribute);

            if ($anchor === null) {
                continue;
            }

            foreach ($rule->signedOffsets() as $offset) {
                if ($offset === null) {
                    continue;
                }

                $due = ReminderRule::dueAt($anchor, $offset);

                if ($due->lessThanOrEqualTo($now)) {
                    continue;
                }

                $rows[] = ReminderWriter::upsert($subject, $type, $rule->ruleKey(), $rule->attribute, $offset, $anchor, $due, $stored, null);
            }
        }

        return $rows;
    }
}
