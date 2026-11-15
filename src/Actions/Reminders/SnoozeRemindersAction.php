<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Exceptions\InvalidReminderRuleException;
use RoundlyConsulting\Notifications\Exceptions\UnknownNotificationTypeException;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\ReminderChains;
use RoundlyConsulting\Notifications\Support\ReminderSnoozes;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * Snoozes one recipient's repeating reminders (`Reminder::every()`) of a type on a subject: their
 * open reminder is due at `$until`, and every later one whole intervals after `$until` — so a
 * manual "remind now" followed by `snooze(until: now + interval)` resets their cadence. Everyone
 * else keeps the original slots. The reminder number (`level`), `times()` and the escalations
 * already armed are untouched; a `$until` after the rule's `until()` ends the chain (`passed`).
 *
 * Idempotent: snoozing to the same instant again changes nothing, and a recipient without an open
 * chain is left alone — a snooze never starts a chain (`schedule(…, restart: true)` does).
 */
final readonly class SnoozeRemindersAction
{
    public function __construct(
        private TypeRegistry $types,
    ) {}

    /**
     * @return int chains moved (or ended)
     *
     * @throws UnknownNotificationTypeException
     * @throws InvalidReminderRuleException when `$until` is not in the future, or the type has no
     *                                      repeating rule
     */
    public function execute(Model $subject, string $type, Model $recipient, CarbonInterface $until): int
    {
        $definition = $this->types->find($type);
        $rules = ReminderSnoozes::rules($definition, $until, Clock::now());
        $until = Clock::utc($until);

        return (int) NotificationModels::reminder()::query()->getConnection()->transaction(static function () use ($subject, $definition, $rules, $recipient, $until): int {
            $changed = 0;

            foreach ($rules as $rule) {
                $changed += ReminderChains::snooze($subject, $definition, $rule, $recipient, $until);
            }

            return $changed;
        });
    }
}
