<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Notifications\Actions\Reminders\SnoozeRemindersAction;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Events\ReminderCancelled;
use RoundlyConsulting\Notifications\Events\ReminderRescheduled;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationSubjectException;
use RoundlyConsulting\Notifications\Exceptions\InvalidReminderRuleException;
use RoundlyConsulting\Notifications\Exceptions\UnknownNotificationTypeException;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Reminders\Reminder;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Contract;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;

/**
 * Controlling repeating chains (1.4): re-timing when only a repeating rule's anchor changes on save,
 * `schedule(…, restart: true)`, `cancel($type, for: $recipient)` and `snooze($type, $recipient,
 * until:)` — through the facade and through the fake.
 */
beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 10:00:00');
});

const POLICY = 'policy.controlled';

function controlled(ReminderRule ...$rules): void
{
    Notifications::define(POLICY)
        ->subject(Contract::class)
        ->access(fn (): bool => true)
        ->title(fn (): string => 'Please acknowledge the policy')
        ->reminders(...$rules)
        ->register();
}

/**
 * A subject published "now" — `expires_at` stands in for `published_at`.
 */
function controlledPolicy(): Contract
{
    return contract(expiresAt: CarbonImmutable::now('UTC'));
}

function sentTo(User $user): int
{
    return NotificationReminder::query()
        ->where('kind', ReminderKind::Reminder->value)
        ->where('type', POLICY)
        ->where('recipient_id', $user->id)
        ->where('status', ReminderStatus::Sent->value)
        ->count();
}

function openRow(User $user): ?NotificationReminder
{
    return NotificationReminder::query()
        ->where('kind', ReminderKind::Reminder->value)
        ->where('type', POLICY)
        ->where('recipient_id', $user->id)
        ->where('status', ReminderStatus::Pending->value)
        ->first();
}

function dueOf(User $user): ?string
{
    return openRow($user)?->due_at->toDateTimeString();
}

function escalationsFor(User $user, ?ReminderStatus $status = null): int
{
    return NotificationReminder::query()
        ->where('kind', ReminderKind::Escalation->value)
        ->where('recipient_id', $user->id)
        ->when($status !== null, fn ($query) => $query->where('status', $status?->value))
        ->count();
}

function fireAt(string $instant): void
{
    at($instant);
    Notifications::reminders()->fireDue();
}

describe('re-timing on an anchor change (N1)', function (): void {
    it('moves pending occurrences when only a repeating rule\'s from() attribute changes on save', function (): void {
        // `terminated_at` anchors nothing else: before 1.4 this save synced nothing.
        controlled(Reminder::every('7 days')->from('terminated_at'));
        $policy = controlledPolicy();
        $policy->forceFill(['terminated_at' => CarbonImmutable::now('UTC')])->saveQuietly();
        [$jane, $john] = [user(), user()];
        Notifications::for($policy)->reminders()->schedule(POLICY, [$jane, $john]);

        expect(dueOf($jane))->toBe('2026-11-16 10:00:00');

        $policy->update(['terminated_at' => CarbonImmutable::parse('2026-11-11 08:00:00', 'UTC')]);

        expect(dueOf($jane))->toBe('2026-11-18 08:00:00')
            ->and(dueOf($john))->toBe('2026-11-18 08:00:00')
            ->and(openRow($jane)?->anchor_value?->toDateTimeString())->toBe('2026-11-11 08:00:00');
    });

    it('starts a rule\'s own recipients\' chains when the anchor is first set on save', function (): void {
        // A draft without the date: no row exists yet that could mark the attribute as an anchor.
        controlled(Reminder::every('7 days')->from('terminated_at')->to(Recipients::owner()));
        $policy = controlledPolicy();

        expect(NotificationReminder::query()->where('type', POLICY)->count())->toBe(0);

        $policy->update(['terminated_at' => CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC')]);

        expect(dueOf($policy->owner))->toBe('2026-11-17 10:00:00');
    });

    it('re-arms ended chains on save when the anchor moves later', function (): void {
        controlled(Reminder::every('7 days')->from('terminated_at'));
        $policy = controlledPolicy();
        $policy->forceFill(['terminated_at' => CarbonImmutable::now('UTC')])->saveQuietly();
        $jane = user();
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane);
        fireAt('2026-11-16 10:00:00');
        Notifications::reminders()->acknowledge($policy, POLICY, by: $jane);

        // Nothing is pending any more; republishing still restarts her chain.
        at('2026-11-18 09:00:00');
        $policy->update(['terminated_at' => CarbonImmutable::parse('2026-11-18 09:00:00', 'UTC')]);

        expect(dueOf($jane))->toBe('2026-11-25 09:00:00')
            ->and(openRow($jane)?->level)->toBe(1);
    });

    it('leaves the chains alone when an unrelated attribute changes', function (): void {
        controlled(Reminder::every('7 days')->from('terminated_at'));
        $policy = controlledPolicy();
        $policy->forceFill(['terminated_at' => CarbonImmutable::now('UTC')])->saveQuietly();
        $jane = user();
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane);

        Event::fake([ReminderRescheduled::class]);
        $policy->update(['title' => 'Renamed']);

        expect(dueOf($jane))->toBe('2026-11-16 10:00:00');
        Event::assertNotDispatched(ReminderRescheduled::class);
    });

    it('re-times through the trait under the fake too, recording the sync', function (): void {
        controlled(Reminder::every('7 days')->from('terminated_at'));
        $policy = controlledPolicy();
        $policy->forceFill(['terminated_at' => CarbonImmutable::now('UTC')])->saveQuietly();
        $jane = user();

        $fake = Notifications::fake()->withReminders();
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane);
        $policy->update(['terminated_at' => CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC')]);

        $fake->assertRemindersSynced($policy);
        expect(dueOf($jane))->toBe('2026-11-19 10:00:00');
    });
});

describe('restart (N2)', function (): void {
    it('restarts a chain that ended through while() for the same anchor', function (): void {
        controlled(Reminder::every('7 days')->from('expires_at')->while(fn (Contract $contract): bool => $contract->terminated_at === null));
        $policy = controlledPolicy();
        $jane = user();
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane);

        fireAt('2026-11-16 10:00:00');
        // Acknowledgement switched off: while() turns false and the chain ends at its next fire.
        $policy->forceFill(['terminated_at' => CarbonImmutable::now('UTC')])->saveQuietly();
        fireAt('2026-11-23 10:00:00');

        expect(openRow($jane))->toBeNull()
            ->and(NotificationReminder::query()->where('cancel_reason', CancelReason::Condition->value)->count())->toBe(1);

        // Switched on again: a plain schedule() refuses — the chain already ran for this anchor.
        at('2026-11-24 09:00:00');
        $policy->forceFill(['terminated_at' => null])->saveQuietly();

        expect(Notifications::for($policy)->reminders()->schedule(POLICY, $jane))->toBe([])
            ->and(openRow($jane))->toBeNull();

        $rows = Notifications::for($policy)->reminders()->schedule(POLICY, $jane, restart: true);

        // A fresh chain at the anchor's next slot, counting from reminder #1.
        expect($rows)->toHaveCount(1)
            ->and(dueOf($jane))->toBe('2026-11-30 10:00:00')
            ->and(openRow($jane)?->level)->toBe(1);

        fireAt('2026-11-30 10:00:00');

        expect(sentTo($jane))->toBe(2)
            ->and(dueOf($jane))->toBe('2026-12-07 10:00:00');
    });

    it('keeps an open chain and restarts only ended ones, with a fresh times() budget', function (): void {
        controlled(Reminder::every('7 days')->from('expires_at')->times(2));
        $policy = controlledPolicy();
        [$jane, $john] = [user(), user()];
        Notifications::for($policy)->reminders()->schedule(POLICY, [$jane, $john]);

        fireAt('2026-11-16 10:00:00');
        Notifications::reminders()->acknowledge($policy, POLICY, by: $jane);
        $johnsRow = openRow($john)?->getKey();
        at('2026-11-17 09:00:00');

        $rows = Notifications::for($policy)->reminders()->schedule(POLICY, [$jane, $john], restart: true);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->recipient_id)->toBe($jane->id)
            ->and(openRow($john)?->getKey())->toBe($johnsRow)
            ->and(openRow($john)?->level)->toBe(2);

        fireAt('2026-11-23 10:00:00');
        fireAt('2026-11-30 10:00:00');

        // John's chain hit times(2); Jane's restarted chain has its own two.
        expect(sentTo($john))->toBe(2)
            ->and(openRow($john))->toBeNull()
            ->and(sentTo($jane))->toBe(3)
            ->and(openRow($jane))->toBeNull();
    });

    it('restarts a cancelled chain, and is idempotent on an open one', function (): void {
        controlled(Reminder::every('7 days')->from('expires_at'));
        $policy = controlledPolicy();
        $jane = user();
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane);
        Notifications::for($policy)->reminders()->cancel(POLICY, for: $jane);

        expect(Notifications::for($policy)->reminders()->schedule(POLICY, $jane, restart: true))->toHaveCount(1)
            ->and(Notifications::for($policy)->reminders()->schedule(POLICY, $jane, restart: true))->toBe([])
            ->and(NotificationReminder::query()->where('recipient_id', $jane->id)->where('status', 'pending')->count())->toBe(1);
    });

    it('restarts a rule\'s own recipient after cancel(for:), which sync alone never does', function (): void {
        controlled(Reminder::every('7 days')->from('expires_at')->to(Recipients::owner()));
        $policy = controlledPolicy();
        $owner = $policy->owner;

        Notifications::for($policy)->reminders()->cancel(POLICY, for: $owner);
        Notifications::for($policy)->reminders()->sync();

        expect(openRow($owner))->toBeNull();

        Notifications::for($policy)->reminders()->schedule(POLICY, $owner, restart: true);

        expect(dueOf($owner))->toBe('2026-11-16 10:00:00');
    });

    it('never repeats a slot the ended chain already sent', function (): void {
        controlled(Reminder::every('7 days')->from('expires_at'));
        $policy = controlledPolicy();
        $jane = user();
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane);

        // Acknowledged and restarted in the very second the reminder went out.
        fireAt('2026-11-16 10:00:00');
        Notifications::reminders()->acknowledge($policy, POLICY, by: $jane);
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane, restart: true);

        expect(dueOf($jane))->toBe('2026-11-23 10:00:00');
    });

    it('refuses an unknown type or a foreign subject', function (): void {
        controlled(Reminder::every('7 days')->from('expires_at'));

        expect(fn () => Notifications::for(controlledPolicy())->reminders()->schedule('policy.nope', user(), restart: true))
            ->toThrow(UnknownNotificationTypeException::class)
            ->and(fn () => Notifications::for(purchaseRequest())->reminders()->schedule(POLICY, user(), restart: true))
            ->toThrow(InvalidNotificationSubjectException::class);
    });
});

describe('cancel for one recipient (N3)', function (): void {
    it('cancels only that recipient\'s chain and escalations, audited and idempotent', function (): void {
        $manager = user(['name' => 'Manager']);
        controlled(Reminder::every('7 days')->from('expires_at')->escalate(1, to: $manager));
        $policy = controlledPolicy();
        [$jane, $john] = [user(), user()];
        Notifications::for($policy)->reminders()->schedule(POLICY, [$jane, $john]);
        fireAt('2026-11-16 10:00:00');

        Event::fake([ReminderCancelled::class]);

        // Her open reminder and the escalation her chain armed.
        expect(Notifications::for($policy)->reminders()->cancel(POLICY, for: $jane))->toBe(2)
            ->and(openRow($jane))->toBeNull()
            ->and(escalationsFor($jane, ReminderStatus::Cancelled))->toBe(1)
            ->and(openRow($john))->not->toBeNull()
            ->and(escalationsFor($john, ReminderStatus::Pending))->toBe(1);

        Event::assertDispatchedTimes(ReminderCancelled::class, 2);

        // Again: nothing left to cancel, nothing dispatched.
        expect(Notifications::for($policy)->reminders()->cancel(POLICY, for: $jane))->toBe(0);
        Event::assertDispatchedTimes(ReminderCancelled::class, 2);

        // A sync never brings her chain back for this anchor; John keeps being reminded.
        Notifications::for($policy)->reminders()->sync();
        fireAt('2026-11-23 10:00:00');

        expect(openRow($jane))->toBeNull()
            ->and(sentTo($jane))->toBe(1)
            ->and(sentTo($john))->toBe(2);
    });

    it('cancels nothing for a recipient without a chain and leaves one-off reminders alone', function (): void {
        controlled(Reminder::every('7 days')->from('expires_at'));
        // contract.expiring's one-off reminders (to the owner) are shared rows.
        $policy = contract(expiresAt: CarbonImmutable::parse('2027-03-01 10:00:00', 'UTC'));
        $jane = user();
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane);
        $oneOff = NotificationReminder::query()->where('type', 'contract.expiring')->where('status', 'pending')->count();

        expect($oneOff)->toBeGreaterThan(0)
            ->and(Notifications::for($policy)->reminders()->cancel(for: $policy->owner))->toBe(0)
            ->and(Notifications::for($policy)->reminders()->cancel('contract.expiring', for: $jane))->toBe(0)
            ->and(NotificationReminder::query()->where('type', 'contract.expiring')->where('status', 'pending')->count())->toBe($oneOff)
            ->and(openRow($jane))->not->toBeNull();

        // Every type: just her chain.
        expect(Notifications::for($policy)->reminders()->cancel(for: $jane))->toBe(1)
            ->and(NotificationReminder::query()->where('type', 'contract.expiring')->where('status', 'pending')->count())->toBe($oneOff);
    });
});

describe('snooze (N3)', function (): void {
    it('moves only that recipient\'s next reminder, and later repeats follow from it', function (): void {
        controlled(Reminder::every('7 days')->from('expires_at'));
        $policy = controlledPolicy();
        [$jane, $john] = [user(), user()];
        Notifications::for($policy)->reminders()->schedule(POLICY, [$jane, $john]);
        fireAt('2026-11-16 10:00:00');

        // A manual "remind now" for Jane on the 18th: her next one a week from then.
        at('2026-11-18 15:00:00');
        Event::fake([ReminderRescheduled::class]);
        $until = CarbonImmutable::parse('2026-11-25 15:00:00', 'UTC');

        expect(Notifications::for($policy)->reminders()->snooze(POLICY, $jane, until: $until))->toBe(1)
            ->and(dueOf($jane))->toBe('2026-11-25 15:00:00')
            ->and(openRow($jane)?->level)->toBe(2)
            ->and(openRow($jane)?->isSnoozed())->toBeTrue()
            ->and(dueOf($john))->toBe('2026-11-23 10:00:00');

        Event::assertDispatchedTimes(ReminderRescheduled::class, 1);

        // The same snooze again changes nothing.
        expect(Notifications::for($policy)->reminders()->snooze(POLICY, $jane, until: $until))->toBe(0);
        Event::assertDispatchedTimes(ReminderRescheduled::class, 1);

        fireAt('2026-11-23 10:00:00');
        expect(sentTo($jane))->toBe(1)->and(sentTo($john))->toBe(2);

        fireAt('2026-11-25 15:00:00');
        expect(sentTo($jane))->toBe(2)
            ->and(dueOf($jane))->toBe('2026-12-02 15:00:00')
            ->and(openRow($jane)?->level)->toBe(3)
            ->and(openRow($jane)?->snoozed_until?->toDateTimeString())->toBe('2026-11-25 15:00:00')
            ->and(dueOf($john))->toBe('2026-11-30 10:00:00');

        // A late sweep skips on the snoozed cadence, without a burst.
        fireAt('2026-12-12 12:00:00');
        expect(dueOf($jane))->toBe('2026-12-16 15:00:00')
            ->and(openRow($jane)?->level)->toBe(3);
    });

    it('keeps level, times() and count escalations', function (): void {
        $manager = user(['name' => 'Manager']);
        controlled(Reminder::every('7 days')->from('expires_at')->times(3)->escalate(2, to: $manager));
        $policy = controlledPolicy();
        $jane = user();
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane);

        fireAt('2026-11-16 10:00:00');
        Notifications::for($policy)->reminders()->snooze(POLICY, $jane, until: CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));

        fireAt('2026-11-20 10:00:00');
        // The 2nd reminder arms the escalation one interval later.
        expect(sentTo($jane))->toBe(2)
            ->and(NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->sole()->due_at->toDateTimeString())->toBe('2026-11-27 10:00:00');

        fireAt('2026-11-27 10:00:00');
        fireAt('2026-12-04 10:00:00');

        // times(3) ends the chain whatever the snooze.
        expect(sentTo($jane))->toBe(3)
            ->and(openRow($jane))->toBeNull()
            ->and(escalationsFor($jane, ReminderStatus::Sent))->toBe(1);
    });

    it('ends the chain when snoozed past until()', function (): void {
        controlled(Reminder::every('7 days')->from('expires_at')->until(CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC')));
        $policy = controlledPolicy();
        $jane = user();
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane);

        expect(Notifications::for($policy)->reminders()->snooze(POLICY, $jane, until: CarbonImmutable::parse('2026-12-02 10:00:00', 'UTC')))->toBe(1)
            ->and(openRow($jane))->toBeNull()
            ->and(NotificationReminder::query()->where('recipient_id', $jane->id)->sole()->cancel_reason)->toBe(CancelReason::Passed);
    });

    it('drops the snooze when the anchor moves', function (): void {
        controlled(Reminder::every('7 days')->from('expires_at'));
        $policy = controlledPolicy();
        $jane = user();
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane);
        Notifications::for($policy)->reminders()->snooze(POLICY, $jane, until: CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC'));

        $policy->update(['expires_at' => CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC')]);

        expect(dueOf($jane))->toBe('2026-11-17 10:00:00')
            ->and(openRow($jane)?->snoozed_until)->toBeNull();
    });

    it('is a no-op for a recipient without an open chain', function (): void {
        controlled(Reminder::every('7 days')->from('expires_at'));
        $policy = controlledPolicy();
        [$jane, $john] = [user(), user()];
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane);

        expect(Notifications::for($policy)->reminders()->snooze(POLICY, $john, until: CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC')))->toBe(0)
            ->and(NotificationReminder::query()->where('recipient_id', $john->id)->count())->toBe(0)
            ->and(dueOf($jane))->toBe('2026-11-16 10:00:00');
    });

    it('refuses an instant not in the future, a type without a repeating rule and an unknown type', function (string $type, string $until, string $exception, string $message): void {
        controlled(Reminder::every('7 days')->from('expires_at'));
        $policy = controlledPolicy();
        $jane = user();
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane);

        expect(fn () => Notifications::for($policy)->reminders()->snooze($type, $jane, until: CarbonImmutable::parse($until, 'UTC')))
            ->toThrow($exception, $message)
            // The raw action refuses the same.
            ->and(fn () => app(SnoozeRemindersAction::class)->execute($policy, $type, $jane, CarbonImmutable::parse($until, 'UTC')))
            ->toThrow($exception, $message)
            ->and(dueOf($jane))->toBe('2026-11-16 10:00:00');
    })->with([
        'now' => [POLICY, '2026-11-09 10:00:00', InvalidReminderRuleException::class, 'is not in the future'],
        'past' => [POLICY, '2026-11-01 10:00:00', InvalidReminderRuleException::class, 'is not in the future'],
        'one-off type' => ['contract.expiring', '2026-11-20 10:00:00', InvalidReminderRuleException::class, 'has no repeating rule'],
        'unknown type' => ['policy.nope', '2026-11-20 10:00:00', UnknownNotificationTypeException::class, 'policy.nope'],
    ]);
});

describe('the fake', function (): void {
    it('runs restart, cancel(for:) and snooze under withReminders() and asserts each', function (): void {
        controlled(Reminder::every('7 days')->from('expires_at')->while(fn (Contract $contract): bool => $contract->terminated_at === null));
        $policy = controlledPolicy();
        [$jane, $john] = [user(), user()];

        $fake = Notifications::fake()->withReminders();
        Notifications::for($policy)->reminders()->schedule(POLICY, [$jane, $john]);

        at('2026-11-16 10:00:00');
        $fake->fireDueReminders();
        Notifications::for($policy)->reminders()->cancel(POLICY, for: $john);
        Notifications::for($policy)->reminders()->snooze(POLICY, $jane, until: CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));

        $policy->forceFill(['terminated_at' => CarbonImmutable::now('UTC')])->saveQuietly();
        at('2026-11-20 10:00:00');
        $fake->fireDueReminders();
        $policy->forceFill(['terminated_at' => null])->saveQuietly();
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane, restart: true);

        $fake->assertReminderScheduled($policy, POLICY, restart: true);
        $fake->assertReminderScheduled($policy, POLICY, restart: false);
        $fake->assertRemindersCancelled($policy, for: $john);
        $fake->assertRemindersSnoozed($policy, $jane, POLICY, CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));
        $fake->assertReminded($jane, POLICY, times: 1);
        $fake->assertReminded($john, POLICY, times: 1);

        expect(dueOf($jane))->toBe('2026-11-23 10:00:00')
            ->and(openRow($john))->toBeNull()
            ->and(fn () => $fake->assertRemindersCancelled($policy, for: $jane))->toThrow(AssertionFailedError::class, 'recipient\'s reminders')
            ->and(fn () => $fake->assertRemindersSnoozed($policy, $john))->toThrow(AssertionFailedError::class, 'snoozed')
            ->and(fn () => $fake->assertRemindersSnoozed($policy, $jane, POLICY, CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC')))->toThrow(AssertionFailedError::class, '['.POLICY.'] reminders')
            ->and(fn () => $fake->assertReminderScheduled(contract(), POLICY, restart: true))->toThrow(AssertionFailedError::class, 'to be restarted');
    });

    it('records without writing, and refuses a bad snooze like production', function (): void {
        controlled(Reminder::every('7 days')->from('expires_at'));
        $policy = controlledPolicy();
        $jane = user();
        Notifications::for($policy)->reminders()->schedule(POLICY, $jane);
        $row = openRow($jane);

        $fake = Notifications::fake();

        expect(Notifications::for($policy)->reminders()->snooze(POLICY, $jane, until: CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC')))->toBe(0)
            ->and(Notifications::for($policy)->reminders()->cancel(POLICY, for: $jane))->toBe(0)
            ->and(Notifications::for($policy)->reminders()->schedule(POLICY, $jane, restart: true))->toBe([])
            ->and(openRow($jane)?->due_at->toDateTimeString())->toBe($row?->due_at->toDateTimeString())
            ->and(fn () => Notifications::for($policy)->reminders()->snooze(POLICY, $jane, until: CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC')))
            ->toThrow(InvalidReminderRuleException::class, 'is not in the future')
            ->and(fn () => Notifications::for($policy)->reminders()->snooze('contract.expiring', $jane, until: CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC')))
            ->toThrow(InvalidReminderRuleException::class, 'has no repeating rule');

        $fake->assertRemindersSnoozed($policy, $jane);
        $fake->assertRemindersCancelled($policy, for: $jane);
        $fake->assertReminderScheduled($policy, POLICY, restart: true);
        $fake->assertRecorded(NotificationOperation::SnoozeReminders, times: 1);
    });
});
