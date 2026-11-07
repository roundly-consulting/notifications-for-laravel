<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationSubjectException;
use RoundlyConsulting\Notifications\Exceptions\InvalidReminderRuleException;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;
use RoundlyConsulting\Notifications\Support\Anchors;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\Payloads;
use RoundlyConsulting\Notifications\Support\ReminderWriter;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * An ad-hoc reminder for one subject: relative to one of its dates (re-timed by sync like a rule
 * reminder) or at an absolute instant. A second remind() with the same key replaces the pending
 * row. No condition or escalation: declare a rule on the type for those.
 */
final readonly class RemindAction
{
    public function __construct(
        private TypeRegistry $types,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return list<NotificationReminder>
     */
    public function execute(Model $subject, string $type, ReminderRule $rule, RecipientSpec $to, array $data = []): array
    {
        $definition = $this->types->find($type);
        $expected = $definition->subject();

        if ($expected !== null && ! $subject instanceof $expected) {
            throw InvalidNotificationSubjectException::wrongClass($type, class_basename($expected), class_basename($subject));
        }

        $stored = $to->toStored();
        $payload = Payloads::assertJson($data);

        if ($rule->direction === 'at') {
            if ($rule->at === null) {
                throw InvalidReminderRuleException::invalid($type, 'Reminder::at() needs an instant');
            }

            $key = 'adhoc:'.$rule->ruleKey();

            return [ReminderWriter::upsert($subject, $type, $key, null, null, null, Clock::utc($rule->at), $stored, $payload)];
        }

        if ($rule->attribute === null || $rule->offsets === []) {
            throw InvalidReminderRuleException::invalid($type, 'a relative reminder needs an attribute and an offset');
        }

        $anchor = Anchors::read($subject, $rule->attribute);

        if ($anchor === null) {
            throw InvalidReminderRuleException::invalid($type, 'the anchor attribute is empty');
        }

        $rows = [];

        foreach ($rule->signedOffsets() as $offset) {
            if ($offset === null) {
                throw InvalidReminderRuleException::invalid($type, 'every offset must be a positive ISO-8601 duration');
            }

            $key = 'adhoc:'.($rule->hasExplicitKey() ? $rule->ruleKey() : $rule->attribute.':'.$offset);

            $rows[] = ReminderWriter::upsert($subject, $type, $key, $rule->attribute, $offset, $anchor, ReminderRule::dueAt($anchor, $offset), $stored, $payload);
        }

        return $rows;
    }
}
