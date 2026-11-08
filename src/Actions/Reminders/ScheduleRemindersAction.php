<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationSubjectException;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;
use RoundlyConsulting\Notifications\Support\Anchors;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\ReminderWriter;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * Schedules a type's reminder rules for one subject with explicit (stored) recipients — for rules
 * written without `->to()`. Future offsets only; a pending row of the same rule is replaced.
 */
final readonly class ScheduleRemindersAction
{
    public function __construct(
        private TypeRegistry $types,
    ) {}

    /**
     * @return list<NotificationReminder>
     */
    public function execute(Model $subject, string $type, RecipientSpec $to): array
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
