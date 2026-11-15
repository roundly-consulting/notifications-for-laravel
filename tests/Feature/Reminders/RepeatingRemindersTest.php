<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Exceptions\InvalidReminderRuleException;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Mail\TypedMailNotification;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Reminders\Reminder;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Contract;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;

/**
 * Repeating reminders (`Reminder::every()`): one chain per recipient, each firing writes the next
 * row, ending on acknowledgement, while(), times(), until() or a cancel.
 */
beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 10:00:00');
});

function repeating(ReminderRule ...$rules): void
{
    Notifications::define('policy.unacknowledged')
        ->subject(Contract::class)
        ->access(fn (): bool => true)
        ->title(fn (): string => 'Please acknowledge the policy')
        ->reminders(...$rules)
        ->register();
}

/**
 * A subject published "now" — `expires_at` stands in for `published_at`.
 */
function published(): Contract
{
    return contract(expiresAt: CarbonImmutable::now('UTC'));
}

function remindedTimes(User $user): int
{
    return NotificationReminder::query()
        ->where('kind', ReminderKind::Reminder->value)
        ->where('recipient_id', $user->id)
        ->where('status', ReminderStatus::Sent->value)
        ->count();
}

function openChainRow(User $user): ?NotificationReminder
{
    return NotificationReminder::query()
        ->where('kind', ReminderKind::Reminder->value)
        ->where('recipient_id', $user->id)
        ->where('status', ReminderStatus::Pending->value)
        ->first();
}

function sweepAt(string $instant): void
{
    at($instant);
    Notifications::reminders()->fireDue();
}

it('reminds every interval until the recipient acknowledges', function (): void {
    repeating(Reminder::every('7 days')->from('expires_at'));
    $policy = published();
    $jane = user();

    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', $jane);

    expect(openChainRow($jane)?->due_at->toDateTimeString())->toBe('2026-11-16 10:00:00');

    sweepAt('2026-11-16 10:00:00');
    sweepAt('2026-11-23 10:00:00');

    expect(remindedTimes($jane))->toBe(2)
        ->and(openChainRow($jane)?->due_at->toDateTimeString())->toBe('2026-11-30 10:00:00')
        ->and(openChainRow($jane)?->level)->toBe(3)
        ->and(openChainRow($jane)?->data)->toBe(['reminder' => 'P7D', 'occurrence' => 3]);

    Notification::assertSentToTimes($jane, TypedMailNotification::class, 2);

    Notifications::reminders()->acknowledge($policy, 'policy.unacknowledged', by: $jane);
    sweepAt('2026-11-30 10:00:00');

    expect(remindedTimes($jane))->toBe(2)
        ->and(openChainRow($jane))->toBeNull()
        ->and(NotificationReminder::query()->where('cancel_reason', CancelReason::Acknowledged->value)->count())->toBe(1);
});

it('carries a distinct idempotency key and inbox entry per firing', function (): void {
    repeating(Reminder::every('P1D')->from('expires_at'));
    $policy = published();
    $jane = user();
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', $jane);

    sweepAt('2026-11-10 10:00:00');
    sweepAt('2026-11-11 10:00:00');

    $ids = NotificationReminder::query()->where('status', 'sent')->pluck('id')->all();

    expect($ids)->toHaveCount(2)
        ->and(InboxItem::query()->whereIn('reminder_id', $ids)->count())->toBeGreaterThanOrEqual(1);

    Notification::assertSentToTimes($jane, TypedMailNotification::class, 2);
});

it('starts at the first delay and counts slots from the anchor', function (): void {
    repeating(Reminder::every('7 days')->from('expires_at')->startingAfter('1 day'));
    $policy = published();
    $jane = user();

    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', $jane);
    expect(openChainRow($jane)?->due_at->toDateTimeString())->toBe('2026-11-10 10:00:00');

    sweepAt('2026-11-10 10:00:00');
    expect(openChainRow($jane)?->due_at->toDateTimeString())->toBe('2026-11-17 10:00:00');
});

it('anchors to when it was scheduled without from()', function (): void {
    repeating(Reminder::every('7 days')->startingAfter('PT0S'));
    $policy = published();
    $jane = user();

    at('2026-11-12 08:00:00');
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', $jane);

    expect(openChainRow($jane)?->due_at->toDateTimeString())->toBe('2026-11-12 08:00:00');

    Notifications::reminders()->fireDue();

    expect(remindedTimes($jane))->toBe(1)
        ->and(openChainRow($jane)?->due_at->toDateTimeString())->toBe('2026-11-19 08:00:00');
});

it('stops when while() turns false', function (): void {
    repeating(Reminder::every('7 days')->from('expires_at')->while(fn (Contract $contract): bool => $contract->terminated_at === null));
    $policy = published();
    $jane = user();
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', $jane);

    sweepAt('2026-11-16 10:00:00');
    $policy->forceFill(['terminated_at' => CarbonImmutable::now('UTC')])->saveQuietly();
    sweepAt('2026-11-23 10:00:00');

    expect(remindedTimes($jane))->toBe(1)
        ->and(openChainRow($jane))->toBeNull()
        ->and(NotificationReminder::query()->where('cancel_reason', CancelReason::Condition->value)->count())->toBe(1);
});

it('stops after times()', function (): void {
    repeating(Reminder::every('7 days')->from('expires_at')->times(2));
    $policy = published();
    $jane = user();
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', $jane);

    sweepAt('2026-11-16 10:00:00');
    sweepAt('2026-11-23 10:00:00');
    sweepAt('2026-11-30 10:00:00');

    expect(remindedTimes($jane))->toBe(2)
        ->and(openChainRow($jane))->toBeNull();
});

it('stops at until(), fixed or read from the subject', function (CarbonImmutable|Closure $end): void {
    repeating(Reminder::every('7 days')->from('expires_at')->until($end));
    $policy = published();
    $jane = user();
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', $jane);

    sweepAt('2026-11-16 10:00:00');
    sweepAt('2026-11-23 10:00:00');

    expect(remindedTimes($jane))->toBe(2)
        ->and(openChainRow($jane))->toBeNull();
})->with([
    'instant' => fn (): CarbonImmutable => CarbonImmutable::parse('2026-11-25 00:00:00', 'UTC'),
    'closure' => fn (): Closure => fn (Contract $contract): CarbonImmutable => $contract->expires_at->addDays(16),
]);

it('ends each recipient\'s chain on their own acknowledgement only', function (): void {
    repeating(Reminder::every('7 days')->from('expires_at'));
    $policy = published();
    [$jane, $john] = [user(), user()];
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', [$jane, $john]);

    sweepAt('2026-11-16 10:00:00');
    Notifications::reminders()->acknowledge($policy, 'policy.unacknowledged', by: $jane);
    sweepAt('2026-11-23 10:00:00');

    expect(remindedTimes($jane))->toBe(1)
        ->and(remindedTimes($john))->toBe(2)
        ->and(openChainRow($jane))->toBeNull()
        ->and(openChainRow($john))->not->toBeNull()
        ->and(NotificationReminder::query()->where('recipient_id', $john->id)->whereNotNull('acknowledged_at')->count())->toBe(0);

    // Without `by`, everyone's chain ends.
    Notifications::reminders()->acknowledge($policy, 'policy.unacknowledged');

    expect(openChainRow($john))->toBeNull();
});

it('ends the reader\'s chain when the rule is acknowledged on read', function (): void {
    repeating(Reminder::every('7 days')->from('expires_at')->acknowledgedOnRead());
    $policy = published();
    [$jane, $john] = [user(), user()];
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', [$jane, $john]);

    sweepAt('2026-11-16 10:00:00');
    Notifications::inbox($jane)->markRead(InboxItem::query()->where('recipient_id', $jane->id)->sole());

    expect(openChainRow($jane))->toBeNull()
        ->and(openChainRow($john))->not->toBeNull();
});

it('lets a late joiner in without resetting anyone else', function (): void {
    repeating(Reminder::every('7 days')->from('expires_at'));
    $policy = published();
    [$jane, $john] = [user(), user()];
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', $jane);

    sweepAt('2026-11-16 10:00:00');
    Notifications::reminders()->acknowledge($policy, 'policy.unacknowledged', by: $jane);

    at('2026-11-18 12:00:00');
    $rows = Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', [$jane, $john]);

    // Jane acknowledged this publication: scheduling her again changes nothing. John joins at the
    // chain's next slot.
    expect($rows)->toHaveCount(1)
        ->and(openChainRow($jane))->toBeNull()
        ->and(openChainRow($john)?->due_at->toDateTimeString())->toBe('2026-11-23 10:00:00')
        ->and(openChainRow($john)?->level)->toBe(1);

    $before = openChainRow($john)?->getKey();
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', [$john]);

    expect(openChainRow($john)?->getKey())->toBe($before)
        ->and(NotificationReminder::query()->where('recipient_id', $john->id)->count())->toBe(1);
});

it('re-times the open reminder when the anchor moves, and re-arms ended chains when it moves later', function (): void {
    repeating(Reminder::every('7 days')->from('expires_at'));
    $policy = published();
    [$jane, $john] = [user(), user()];
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', [$jane, $john]);

    sweepAt('2026-11-16 10:00:00');
    Notifications::reminders()->acknowledge($policy, 'policy.unacknowledged', by: $jane);

    // Republished on the 18th: John's open reminder follows the new anchor, Jane's acknowledged
    // chain starts over for the new publication.
    at('2026-11-18 09:00:00');
    $policy->forceFill(['expires_at' => CarbonImmutable::parse('2026-11-18 09:00:00', 'UTC')])->saveQuietly();
    Notifications::for($policy)->reminders()->sync();

    expect(openChainRow($john)?->due_at->toDateTimeString())->toBe('2026-11-25 09:00:00')
        ->and(openChainRow($jane)?->due_at->toDateTimeString())->toBe('2026-11-25 09:00:00')
        ->and(openChainRow($jane)?->level)->toBe(1);

    // A date changed behind the model's back is followed at fire time instead of firing.
    $policy->forceFill(['expires_at' => CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC')])->saveQuietly();
    sweepAt('2026-11-25 09:00:00');

    expect(remindedTimes($john))->toBe(1)
        ->and(openChainRow($john)?->due_at->toDateTimeString())->toBe('2026-11-27 09:00:00');

    // A cleared anchor ends the chain.
    $policy->forceFill(['expires_at' => null])->saveQuietly();
    Notifications::for($policy)->reminders()->sync();

    expect(openChainRow($john))->toBeNull();
});

it('follows the anchor on save through the model trait', function (): void {
    repeating(Reminder::every('7 days')->from('expires_at'));
    $policy = published();
    $jane = user();
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', $jane);

    $policy->update(['expires_at' => CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC')]);

    expect(openChainRow($jane)?->due_at->toDateTimeString())->toBe('2026-11-17 10:00:00');
});

it('skips a late reminder but keeps the chain, without a burst', function (): void {
    repeating(Reminder::every('7 days')->from('expires_at'));
    $policy = published();
    $jane = user();
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', $jane);

    // The sweep was down for three weeks.
    sweepAt('2026-12-01 12:00:00');

    expect(remindedTimes($jane))->toBe(0)
        ->and(NotificationReminder::query()->where('status', ReminderStatus::Skipped->value)->count())->toBe(1)
        ->and(openChainRow($jane)?->due_at->toDateTimeString())->toBe('2026-12-07 10:00:00')
        ->and(openChainRow($jane)?->level)->toBe(1);
});

it('ends every chain on cancel, and sync does not restart them', function (): void {
    repeating(Reminder::every('7 days')->from('expires_at')->to(Recipients::owner()));
    $policy = published();

    expect(NotificationReminder::query()->where('status', 'pending')->count())->toBe(1);

    Notifications::for($policy)->reminders()->cancel('policy.unacknowledged');
    Notifications::for($policy)->reminders()->sync();

    expect(NotificationReminder::query()->where('status', 'pending')->count())->toBe(0);
});

it('starts chains for a rule\'s own recipients when the subject is created', function (): void {
    repeating(Reminder::every('7 days')->to(Recipients::owner()));
    $policy = published();

    expect(openChainRow($policy->owner)?->due_at->toDateTimeString())->toBe('2026-11-16 10:00:00');

    // Every later save syncs: the chain is never started twice.
    $policy->update(['title' => 'Renamed']);

    expect(NotificationReminder::query()->count())->toBe(1);
});

it('escalates after a number of unacknowledged reminders, per recipient', function (): void {
    $manager = user(['name' => 'Manager']);
    repeating(Reminder::every('7 days')->from('expires_at')->escalate(2, to: $manager));
    $policy = published();
    [$jane, $john] = [user(), user()];
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', [$jane, $john]);

    sweepAt('2026-11-16 10:00:00');
    expect(NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->count())->toBe(0);

    sweepAt('2026-11-23 10:00:00');
    $escalations = NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->orderBy('recipient_id')->get();

    // One per chain, due one interval after the second reminder.
    expect($escalations)->toHaveCount(2)
        ->and($escalations->pluck('due_at')->map->toDateTimeString()->unique()->all())->toBe(['2026-11-30 10:00:00']);

    // Jane acknowledges: her escalation goes, John's stays.
    Notifications::reminders()->acknowledge($policy, 'policy.unacknowledged', by: $jane);
    sweepAt('2026-11-30 10:00:00');

    expect(NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->where('status', 'sent')->sole()->recipient_id)->toBe($john->id)
        ->and(NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->where('recipient_id', $jane->id)->sole()->cancel_reason)->toBe(CancelReason::Acknowledged);

    Notification::assertSentToTimes($manager, TypedMailNotification::class, 1);

    // The chain keeps reminding John; the level is not armed again.
    sweepAt('2026-12-07 10:00:00');

    expect(remindedTimes($john))->toBe(4)
        ->and(NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->count())->toBe(2);
});

it('escalates once per chain after a delay counted from the first reminder', function (): void {
    $manager = user(['name' => 'Manager']);
    repeating(Reminder::every('7 days')->from('expires_at')->escalate('P10D', to: $manager));
    $policy = published();
    $jane = user();
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', $jane);

    sweepAt('2026-11-16 10:00:00');
    sweepAt('2026-11-23 10:00:00');

    expect(NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->sole()->due_at->toDateTimeString())->toBe('2026-11-26 10:00:00');

    sweepAt('2026-11-26 10:00:00');

    Notification::assertSentToTimes($manager, TypedMailNotification::class, 1);
    expect(NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->count())->toBe(1);
});

it('holds a reminder in the recipient\'s quiet hours like any other send', function (): void {
    repeating(Reminder::every('7 days')->from('expires_at'));
    $policy = published();
    $jane = user();
    Notifications::preferences($jane)->quietHours('09:00', '11:00');
    Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', $jane);

    sweepAt('2026-11-16 10:00:00');
    Notification::assertNothingSentTo($jane);

    at('2026-11-16 11:00:00');
    Notifications::sweep();
    Notification::assertSentToTimes($jane, TypedMailNotification::class, 1);
});

it('refuses repeat settings on one-off rules and broken repeating rules', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidReminderRuleException::class, $message);
})->with([
    'from on before' => [fn () => Reminder::before('expires_at', 'P7D')->from('expires_at'), 'from() is for repeating rules'],
    'times on after' => [fn () => Reminder::after('expires_at', 'P7D')->times(2), 'times() is for repeating rules'],
    'count escalation on one-off' => [fn () => Reminder::before('expires_at', 'P7D')->escalate(2, to: Recipients::owner()), 'needs Reminder::every()'],
    'unreadable interval' => [fn () => Reminder::every('every week-ish'), 'is not a positive duration'],
    'zero interval' => [fn () => Reminder::every('PT0S'), 'is not a positive duration'],
    'times below one' => [fn () => Reminder::every('P7D')->times(0), 'at least 1'],
    'negative first delay' => [fn () => Reminder::every('P7D')->startingAfter('-1 day'), 'zero or more'],
]);

it('refuses a repeating rule as an ad-hoc reminder', function (): void {
    repeating(Reminder::before('expires_at', 'P1D'));
    $policy = published();

    expect(fn () => Notifications::for($policy)->remind('policy.unacknowledged', Reminder::every('P1D'), to: user()))
        ->toThrow(InvalidReminderRuleException::class);
});

it('refuses two repeating rules on one anchor without a key', function (): void {
    expect(fn () => repeating(Reminder::every('P7D')->from('expires_at'), Reminder::every('P1D')->from('expires_at')))
        ->toThrow(InvalidReminderRuleException::class, 'unique');
});

describe('the fake', function (): void {
    it('fires due reminders and counts them per recipient', function (): void {
        repeating(Reminder::every('7 days')->from('expires_at'));
        $policy = published();
        [$jane, $john] = [user(), user()];

        $fake = Notifications::fake()->withReminders();
        Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', [$jane, $john]);

        at('2026-11-16 10:00:00');
        $fake->fireDueReminders();
        Notifications::reminders()->acknowledge($policy, 'policy.unacknowledged', by: $jane);
        at('2026-11-23 10:00:00');
        $fake->fireDueReminders();

        $fake->assertReminded($jane, 'policy.unacknowledged', times: 1);
        $fake->assertReminded($john, 'policy.unacknowledged', times: 2, about: $policy);
        $fake->assertAcknowledged($policy, 'policy.unacknowledged');
        $fake->assertSent('policy.unacknowledged', $john, times: 2);
        Notification::assertNothingSent();

        expect(fn () => $fake->assertReminded($jane, 'policy.unacknowledged', times: 2))
            ->toThrow(AssertionFailedError::class, 'reminded 1 time(s)')
            ->and(fn () => $fake->assertNotReminded($john, 'policy.unacknowledged'))
            ->toThrow(AssertionFailedError::class);
    });

    it('records escalations of a repeating rule', function (): void {
        $manager = user(['name' => 'Manager']);
        repeating(Reminder::every('7 days')->from('expires_at')->escalate(1, to: $manager));
        $policy = published();
        $jane = user();

        $fake = Notifications::fake()->withReminders();
        Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', $jane);

        at('2026-11-16 10:00:00');
        $fake->fireDueReminders();
        at('2026-11-23 10:00:00');
        $fake->fireDueReminders();

        $fake->assertEscalated('policy.unacknowledged', to: $manager, times: 1);
        $fake->assertNotReminded($manager, 'policy.unacknowledged');
    });

    it('keeps reminder operations record-only without withReminders()', function (): void {
        repeating(Reminder::every('7 days')->from('expires_at'));
        $policy = published();

        $fake = Notifications::fake();
        Notifications::for($policy)->reminders()->schedule('policy.unacknowledged', user());

        $fake->assertReminderScheduled($policy, 'policy.unacknowledged');
        expect(NotificationReminder::query()->count())->toBe(0);
    });
});
