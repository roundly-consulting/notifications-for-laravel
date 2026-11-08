<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Notifications\Actions\Reminders\SyncRemindersAction;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Events\EscalationFired;
use RoundlyConsulting\Notifications\Events\InboxItemCreated;
use RoundlyConsulting\Notifications\Events\ReminderCancelled;
use RoundlyConsulting\Notifications\Events\ReminderFired;
use RoundlyConsulting\Notifications\Events\ReminderRescheduled;
use RoundlyConsulting\Notifications\Events\ReminderScheduled;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationSubjectException;
use RoundlyConsulting\Notifications\Exceptions\InvalidReminderRuleException;
use RoundlyConsulting\Notifications\Exceptions\UnstorableRecipientsException;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Mail\TypedMailNotification;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Reminders\Reminder;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Contract;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\PurchaseRequest;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Types\ContractExpiring;
use RoundlyConsulting\Notifications\Traits\HasNotificationReminders;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;

beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 10:00:00');
});

/**
 * @return list<string>
 */
function pendingDue(Contract $contract): array
{
    return Notifications::for($contract)->reminders()->pending()->where('kind', ReminderKind::Reminder)->values()->map(fn (NotificationReminder $r): string => $r->offset.'@'.$r->due_at->format('Y-m-d'))->all();
}

function expiringContract(string $expiresAt = '2027-02-17 10:00:00'): Contract
{
    $company = company();
    $manager = user(['name' => 'Manager']);
    $company->addMember($manager, 'manager');

    return contract(company: $company, expiresAt: CarbonImmutable::parse($expiresAt, 'UTC'));
}

it('schedules the type\'s reminders when the subject is created', function (): void {
    Event::fake([ReminderScheduled::class]);
    $contract = expiringContract();

    expect(pendingDue($contract))->toBe(['-P60D@2026-12-19', '-P30D@2027-01-18', '-P7D@2027-02-10']);
    Event::assertDispatchedTimes(ReminderScheduled::class, 3);
});

it('moves pending reminders when the date changes (A-7)', function (): void {
    Event::fake([ReminderRescheduled::class]);
    $contract = expiringContract();

    $contract->update(['expires_at' => CarbonImmutable::parse('2027-03-19 10:00:00', 'UTC')]);

    expect(pendingDue($contract))->toBe(['-P60D@2027-01-18', '-P30D@2027-02-17', '-P7D@2027-03-12']);
    Event::assertDispatchedTimes(ReminderRescheduled::class, 3);
});

it('does not re-sync when another attribute changes', function (): void {
    Event::fake([ReminderRescheduled::class, ReminderScheduled::class]);
    $contract = expiringContract();

    $contract->update(['title' => 'Renamed']);

    Event::assertDispatchedTimes(ReminderScheduled::class, 3);
    Event::assertNotDispatched(ReminderRescheduled::class);
});

it('cancels the reminders when the subject is deleted (A-7, C-08) and re-schedules them on restore (C-08b)', function (): void {
    Event::fake([ReminderCancelled::class]);
    $contract = expiringContract();

    $contract->delete();

    expect(pendingDue($contract))->toBe([])
        ->and(NotificationReminder::query()->pluck('cancel_reason')->unique()->all())->toBe([CancelReason::SubjectDeleted]);
    Event::assertDispatchedTimes(ReminderCancelled::class, 3);

    $contract->restore();

    expect(pendingDue($contract))->toHaveCount(3);
});

it('cancels the pending reminders when the anchor is cleared', function (): void {
    $contract = expiringContract();

    $contract->update(['expires_at' => null]);

    expect(pendingDue($contract))->toBe([])
        ->and(NotificationReminder::query()->pluck('cancel_reason')->unique()->all())->toBe([CancelReason::AnchorCleared]);
});

it('never back-fills offsets already past at scheduling (C-53)', function (): void {
    $contract = expiringContract('2026-12-01 10:00:00');

    expect(pendingDue($contract))->toBe(['-P7D@2026-11-24']);
});

it('cancels offsets skipped by an earlier anchor instead of firing them (C-53b)', function (): void {
    $contract = expiringContract();

    $contract->update(['expires_at' => CarbonImmutable::parse('2026-11-29 10:00:00', 'UTC')]);

    expect(pendingDue($contract))->toBe(['-P7D@2026-11-22'])
        ->and(NotificationReminder::query()->where('cancel_reason', CancelReason::Passed->value)->count())->toBe(2);

    at('2026-11-22 10:00:00');
    Notifications::sweep();

    Notification::assertSentToTimes($contract->owner, TypedMailNotification::class, 1);
});

it('fires a due reminder to the owner, with the offset in the data', function (): void {
    Event::fake([ReminderFired::class]);
    $contract = expiringContract();

    at('2026-12-19 10:00:00');
    $report = Notifications::sweep();

    expect($report->reminders)->toBe(1)
        ->and(InboxItem::query()->sole()->title)->toBe('Contract #'.$contract->id.' expires soon (-P60D)')
        ->and(InboxItem::query()->sole()->reminder_id)->not->toBeNull()
        ->and(NotificationReminder::query()->where('status', 'sent')->count())->toBe(1);
    Notification::assertSentTo($contract->owner, TypedMailNotification::class);
    Event::assertDispatchedTimes(ReminderFired::class, 1);
});

it('gives a recipient who lost access nothing from a pending reminder (A-3)', function (): void {
    $contract = expiringContract();
    DB::table('company_user')->where('user_id', $contract->owner_id)->delete();

    at('2026-12-19 10:00:00');
    Notifications::sweep();

    expect(InboxItem::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('cancels a reminder whose condition fails, and does not churn it on save (C-55, C-59b)', function (): void {
    Event::fake([ReminderScheduled::class]);
    $contract = expiringContract();
    $contract->update(['terminated_at' => now()]);

    at('2026-12-19 10:00:00');
    Notifications::sweep();

    expect(NotificationReminder::query()->where('cancel_reason', CancelReason::Condition->value)->count())->toBe(1);

    $contract->update(['title' => 'Again']);
    Notifications::for($contract)->reminders()->sync();

    Event::assertDispatchedTimes(ReminderScheduled::class, 3);
    Notification::assertNothingSent();
});

it('reschedules at fire time when the date changed behind the model\'s back (C-54)', function (): void {
    $contract = expiringContract();
    DB::table('contracts')->where('id', $contract->id)->update(['expires_at' => '2027-03-19 10:00:00']);

    at('2026-12-19 10:00:00');
    Notifications::sweep();

    Notification::assertNothingSent();
    expect(NotificationReminder::query()->where('offset', '-P60D')->where('status', 'pending')->sole()->due_at->format('Y-m-d'))->toBe('2027-01-18');
});

it('cancels at fire time when the moved date makes the reminder past', function (): void {
    $contract = expiringContract();
    DB::table('contracts')->where('id', $contract->id)->update(['expires_at' => '2027-01-10 10:00:00']);

    at('2026-12-19 10:00:00');
    Notifications::sweep();

    expect(NotificationReminder::query()->where('offset', '-P60D')->sole()->cancel_reason)->toBe(CancelReason::Passed);
});

it('does not bounce a reminder over a sub-second anchor difference (C-84)', function (): void {
    $contract = expiringContract();
    DB::table('contracts')->where('id', $contract->id)->update(['expires_at' => '2027-02-17 10:00:00.400']);

    at('2026-12-19 10:00:00');
    Notifications::sweep();

    Notification::assertSentToTimes($contract->owner, TypedMailNotification::class, 1);
});

it('sends a late reminder while its date is still ahead (OQ-21)', function (): void {
    $contract = expiringContract('2026-12-25 10:00:00');

    at('2026-12-21 10:00:00');
    Notifications::sweep();

    expect(NotificationReminder::query()->where('status', 'sent')->count())->toBe(2);
});

it('skips a reminder too late after downtime (C-56)', function (): void {
    $contract = expiringContract('2026-12-25 10:00:00');

    at('2026-12-27 10:00:00');
    Notifications::sweep();

    expect(NotificationReminder::query()->where('offset', '-P7D')->sole()->status)->toBe(ReminderStatus::Skipped)
        ->and(NotificationReminder::query()->where('offset', '-P7D')->sole()->cancel_reason)->toBe(CancelReason::Late);
    Notification::assertNothingSent();
});

it('escalates to the managers when nobody acknowledged (C-58), and not after an acknowledgement (C-57)', function (): void {
    Event::fake([EscalationFired::class]);
    $contract = expiringContract();
    $manager = $contract->company->users()->wherePivot('role', 'manager')->sole();

    at('2026-12-19 10:00:00');
    Notifications::sweep();
    $escalation = NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->sole();
    expect($escalation->due_at->format('Y-m-d'))->toBe('2026-12-22');

    at('2026-12-22 10:00:00');
    expect(Notifications::sweep()->escalations)->toBe(1);
    Notification::assertSentTo($manager, TypedMailNotification::class);
    Event::assertDispatchedTimes(EscalationFired::class, 1);

    // The next offset fires and escalates again — this time someone acknowledges.
    at('2027-01-18 10:00:00');
    Notifications::sweep();
    Notifications::for($contract)->acknowledge('contract.expiring', by: $contract->owner);

    at('2027-01-21 10:00:00');
    Notifications::sweep();

    Notification::assertSentToTimes($manager, TypedMailNotification::class, 1);
    expect(NotificationReminder::query()->where('cancel_reason', CancelReason::Acknowledged->value)->count())->toBe(1)
        ->and(NotificationReminder::query()->whereNotNull('acknowledged_at')->first()->acknowledger_id)->toBe($contract->owner_id);
});

it('escalates a later offset even after an earlier one was acknowledged', function (): void {
    $contract = expiringContract();
    $manager = $contract->company->users()->wherePivot('role', 'manager')->sole();

    at('2026-12-19 10:00:00');
    Notifications::sweep();
    at('2026-12-22 10:00:00');
    Notifications::sweep();                                                          // -P60D escalates
    at('2027-01-18 10:00:00');
    Notifications::sweep();                                                          // -P30D fires
    Notifications::for($contract)->acknowledge('contract.expiring', by: $contract->owner);

    $sevenDays = NotificationReminder::query()->where('kind', ReminderKind::Reminder->value)->where('offset', '-P7D')->sole();
    expect($sevenDays->status)->toBe(ReminderStatus::Pending)
        ->and($sevenDays->acknowledged_at)->toBeNull();

    at('2027-02-10 10:00:00');
    Notifications::sweep();                                                          // -P7D fires, arms its escalation
    at('2027-02-13 10:00:00');
    Notifications::sweep();

    Notification::assertSentToTimes($manager, TypedMailNotification::class, 2);
});

it('re-arms sent reminders when a renewal moves the date later (C-59)', function (): void {
    $contract = expiringContract('2026-12-25 10:00:00');

    at('2026-12-18 10:00:00');
    Notifications::sweep();
    expect(NotificationReminder::query()->where('offset', '-P7D')->sole()->status)->toBe(ReminderStatus::Sent);

    $contract->update(['expires_at' => CarbonImmutable::parse('2027-12-25 10:00:00', 'UTC')]);

    expect(pendingDue($contract))->toBe(['-P60D@2027-10-26', '-P30D@2027-11-25', '-P7D@2027-12-18']);
});

it('does not re-arm a rule declared rearm(false)', function (): void {
    Notifications::define('once.only')->subject(Post::class)->access(fn (): bool => true)
        ->reminders(Reminder::before('created_at', 'PT1H')->to(Recipients::users(user()))->rearm(false))
        ->title(fn (): string => 'x')->register();
    Notifications::define('later.only')->subject(Post::class)->access(fn (): bool => true)
        ->reminders(Reminder::after('created_at', 'PT1H')->to(Recipients::users(user()))->rearm(false))
        ->title(fn (): string => 'x')->register();

    $post = post();
    Notifications::for($post)->reminders()->sync();

    at('2026-11-09 11:00:00');
    Notifications::sweep();
    DB::table('posts')->where('id', $post->id)->update(['created_at' => '2026-11-10 10:00:00']);
    Notifications::for($post->fresh())->reminders()->sync('later.only');

    expect(NotificationReminder::query()->where('type', 'later.only')->where('status', 'pending')->count())->toBe(0);
});

it('never folds an escalation into the original send (C-79)', function (): void {
    $approver = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');

    Notifications::type('request.awaiting_approval')->about($request)->to($approver)->with(['reference' => 'PR-1'])
        ->once('submitted:1')
        ->escalate(after: 'PT2M', to: $approver)
        ->send();

    at('2026-11-09 10:02:00');
    Notifications::sweep();

    expect(InboxItem::query()->count())->toBe(2);
    Notification::assertSentToTimes($approver, TypedMailNotification::class, 2);
});

it('chains send-level escalation levels and stops them on acknowledgement', function (): void {
    $approver = user();
    $director = user();
    $board = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver')->addMember($director, 'director')->addMember($board, 'board');

    Notifications::type('request.awaiting_approval')->about($request)->to($approver)->with(['reference' => 'PR-1'])
        ->escalate(after: 'P1D', to: Recipients::role($request->company, 'director'))
        ->escalate(after: 'P2D', to: Recipients::role($request->company, 'board'), type: 'security.alert')
        ->send();

    at('2026-11-10 10:00:00');
    Notifications::sweep();
    Notification::assertSentTo($director, TypedMailNotification::class);
    // The item keeps the send's data; `_notifications` carries the escalation for later renders (O-12).
    expect(InboxItem::query()->where('recipient_id', $director->id)->sole()->data)->toMatchArray(['reference' => 'PR-1'])
        ->and(InboxItem::query()->where('recipient_id', $director->id)->sole()->data['_notifications']['escalation'])->toBe(1);

    Notifications::for($request)->acknowledge();

    at('2026-11-12 10:00:00');
    Notifications::sweep();
    Notification::assertNotSentTo($board, TypedMailNotification::class);
});

it('arms one escalation chain for repeated sends about one subject', function (): void {
    $approver = user();
    $director = user();
    $request = purchaseRequest();
    $other = purchaseRequest(company: $request->company, reference: 'PR-2');
    $request->company->addMember($approver, 'approver')->addMember($director, 'director');
    $send = fn ($subject) => Notifications::type('request.awaiting_approval')->about($subject)->to($approver)->with(['reference' => 'PR'])
        ->escalate(after: 'P1D', to: $director)
        ->send();
    $pending = fn () => NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->where('status', ReminderStatus::Pending->value);

    $send($request);
    at('2026-11-09 10:10:00');
    $send($request);
    at('2026-11-09 10:20:00');
    $send($request);
    $send($other);

    expect($pending()->count())->toBe(2)
        ->and($pending()->where('subject_id', $request->id)->sole()->due_at->format('Y-m-d H:i'))->toBe('2026-11-10 10:00');

    at('2026-11-10 10:30:00');
    Notifications::sweep();

    Notification::assertSentToTimes($director, TypedMailNotification::class, 2);
    expect($pending()->count())->toBe(0);

    $send($request);

    expect($pending()->count())->toBe(1);
});

it('does not re-arm a keyed send\'s escalation on replay', function (Closure $between): void {
    $approver = user();
    $director = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver')->addMember($director, 'director');
    $send = fn () => Notifications::type('request.awaiting_approval')->about($request)->to($approver)->with(['reference' => 'PR-1'])
        ->once('submitted:1')
        ->escalate(after: 'P1D', to: $director)
        ->send();

    $send();
    $between($request);
    $sentBefore = Notification::sent($director, TypedMailNotification::class)->count();

    at('2026-11-10 11:00:00');
    $send();
    at('2026-11-11 11:00:00');
    Notifications::sweep();

    expect(Notification::sent($director, TypedMailNotification::class)->count())->toBe($sentBefore)
        ->and(NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->where('status', ReminderStatus::Pending->value)->count())->toBe(0);
})->with([
    'after level 1 fired' => [function (): void {
        at('2026-11-10 10:00:00');
        Notifications::sweep();
    }],
    'after an acknowledgement' => [fn ($request) => Notifications::for($request)->acknowledge()],
]);

it('does not re-arm a keyed fan-out\'s escalation on replay, but arms it for a new recipient', function (): void {
    config()->set('notifications.fan_out.queue_above', 1);
    $director = user();
    $first = user();
    $second = user();
    $request = purchaseRequest();
    $request->company->addMember($first, 'approver')->addMember($second, 'approver')->addMember($director, 'director');
    $escalations = fn () => NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->count();
    $send = fn (array $to) => Notifications::type('request.awaiting_approval')->about($request)->to($to)->with(['reference' => 'PR-1'])
        ->once('submitted:1')->escalate(after: 'PT1H', to: $director)->send();

    expect($send([$first, $second])->queued)->toBe(2)
        ->and($escalations())->toBe(1);

    at('2026-11-09 11:00:00');
    Notifications::sweep();
    $send([$first, $second]);

    expect($escalations())->toBe(1);

    $send([$first, $second, user()]);

    expect($escalations())->toBe(2);
});

it('arms no escalation when the send throws', function (): void {
    Notifications::define('flaky.access')->subject(PurchaseRequest::class)
        ->access(fn (): bool => throw new RuntimeException('access backend down'))
        ->title(fn (): string => 'x')->register();
    $request = purchaseRequest();

    expect(fn () => Notifications::type('flaky.access')->about($request)->to(user())->escalate('P1D', user())->send())
        ->toThrow(RuntimeException::class, 'access backend down')
        ->and(NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->count())->toBe(0);
});

it('requires a subject to escalate a send', function (): void {
    Notifications::type('security.alert')->to(user())->escalate('P1D', user())->send();
})->throws(InvalidNotificationSubjectException::class, 'needs a subject here');

it('re-fires a reminder after a crash without sending twice (C-25)', function (): void {
    $contract = expiringContract();

    at('2026-12-19 10:00:00');
    Notifications::sweep();

    // A worker died after the send, before the row closed: the lease expires and it fires again.
    $reminder = NotificationReminder::query()->where('offset', '-P60D')->sole();
    $reminder->forceFill(['status' => ReminderStatus::Firing, 'claimed_at' => now()->subMinutes(11), 'active_key' => 'x'])->save();

    Notifications::sweep();
    Notifications::sweep();

    Notification::assertSentToTimes($contract->owner, TypedMailNotification::class, 1);
    expect(InboxItem::query()->count())->toBe(1);
});

it('schedules an ad-hoc reminder relative to a date, and replaces it on the same key', function (): void {
    $post = post();
    $user = user();

    Notifications::for($post)->remind('comment.posted', Reminder::before('created_at', 'PT1H')->key('soon'), to: $user, data: ['title' => 'A']);
    $rows = Notifications::for($post)->remind('comment.posted', Reminder::after('created_at', 'PT2H')->key('soon'), to: $user, data: ['title' => 'B']);

    expect(Notifications::for($post)->reminders()->pending())->toHaveCount(1)
        ->and($rows[0]->due_at->format('H:i'))->toBe('12:00')
        ->and($rows[0]->data)->toBe(['title' => 'B']);

    // It follows its anchor like a rule reminder.
    $post->forceFill(['created_at' => '2026-11-09 11:00:00'])->save();
    Notifications::for($post)->reminders()->sync();
    expect(Notifications::for($post)->reminders()->pending()->sole()->due_at->format('H:i'))->toBe('13:00');

    at('2026-11-09 13:00:00');
    Notifications::sweep();

    expect(InboxItem::query()->sole()->title)->toBe('1 new comment on B');
});

it('schedules an ad-hoc reminder at an instant (a scheduled send)', function (): void {
    $report = purchaseRequest();
    $user = user();
    $report->company->addMember($user, 'reader');

    Notifications::for($report)->remind('request.awaiting_approval', Reminder::at(CarbonImmutable::parse('2026-11-10 09:00', 'UTC')), to: $user, data: ['reference' => 'R']);

    at('2026-11-10 09:00:00');
    Notifications::sweep();

    Notification::assertSentTo($user, TypedMailNotification::class);
});

it('refuses closures and broken ad-hoc rules', function (Closure $call, string $exception): void {
    expect($call)->toThrow($exception);
})->with([
    'closure recipients' => [fn () => Notifications::for(post())->remind('comment.posted', Reminder::at(now()), to: Recipients::role(fn () => company(), 'x')), UnstorableRecipientsException::class],
    'no offsets' => [fn () => Notifications::for(post())->remind('comment.posted', Reminder::before('created_at'), to: user()), InvalidReminderRuleException::class],
    'empty anchor' => [fn () => Notifications::for(post())->remind('comment.posted', Reminder::before('deleted_at', 'PT1H'), to: user()), InvalidReminderRuleException::class],
    'bad offset' => [fn () => Notifications::for(post())->remind('comment.posted', Reminder::before('created_at', 'nope'), to: user()), InvalidReminderRuleException::class],
    'wrong subject' => [fn () => Notifications::for(company())->remind('comment.posted', Reminder::at(now()), to: user()), InvalidNotificationSubjectException::class],
]);

it('schedules a type\'s template rules with explicit recipients', function (): void {
    Notifications::define('task.due')->subject(Contract::class)
        ->reminders(Reminder::before('expires_at', 'P1D', 'P10D'))
        ->title(fn (): string => 'Due')->register();
    $contract = expiringContract('2026-11-15 10:00:00');
    $assignee = user();
    $contract->company->addMember($assignee, 'assignee');

    Notifications::for($contract)->reminders()->sync('task.due');
    expect(Notifications::for($contract)->reminders()->pending('task.due'))->toHaveCount(0);

    $rows = Notifications::for($contract)->reminders()->schedule('task.due', $assignee);

    expect($rows)->toHaveCount(1)
        ->and(Notifications::for($contract)->reminders()->all('task.due'))->toHaveCount(1);

    at('2026-11-14 10:00:00');
    Notifications::sweep();

    Notification::assertSentTo($assignee, TypedMailNotification::class);
    expect(fn () => Notifications::for(post())->reminders()->schedule('task.due', user()))->toThrow(InvalidNotificationSubjectException::class);
});

it('cancels reminders explicitly', function (): void {
    $contract = expiringContract();

    expect(Notifications::for($contract)->reminders()->cancel('contract.expiring'))->toBe(3)
        ->and(pendingDue($contract))->toBe([]);
});

it('cancels a reminder whose type or rule is no longer registered', function (): void {
    $contract = expiringContract();
    NotificationReminder::query()->where('offset', '-P60D')->update(['rule' => 'gone:rule']);
    NotificationReminder::query()->where('offset', '-P30D')->update(['type' => 'gone.type', 'due_at' => '2026-12-19 10:00:00']);

    at('2026-12-19 10:00:00');
    Notifications::sweep();

    expect(NotificationReminder::query()->whereNotNull('cancel_reason')->pluck('cancel_reason')->all())->toEqualCanonicalizing([CancelReason::UnknownRule, CancelReason::UnknownType]);
});

it('cancels a reminder whose subject is gone at fire time', function (): void {
    $contract = expiringContract();
    DB::table('contracts')->where('id', $contract->id)->delete();

    at('2026-12-19 10:00:00');
    Notifications::sweep();

    expect(NotificationReminder::query()->where('offset', '-P60D')->sole()->cancel_reason)->toBe(CancelReason::SubjectDeleted);
});

it('backfills reminders for subjects that existed before the type', function (): void {
    $contract = expiringContract();
    NotificationReminder::query()->forceDelete();

    expect(Notifications::reminders()->backfill('contract.expiring'))->toBe(1)
        ->and(pendingDue($contract))->toHaveCount(3)
        ->and(fn () => Notifications::reminders()->backfill('security.alert'))->toThrow(InvalidReminderRuleException::class);
});

it('acknowledges on read for a rule that says so', function (): void {
    Notifications::define('ack.read')->subject(Contract::class)
        ->reminders(Reminder::before('expires_at', 'P1D')->to(Recipients::owner())->acknowledgedOnRead()->escalate('P1D', Recipients::owner()))
        ->title(fn (): string => 'x')->register();
    $contract = expiringContract('2026-11-11 10:00:00');

    at('2026-11-10 10:00:00');
    Notifications::sweep();

    $item = InboxItem::query()->where('type', 'ack.read')->sole();
    Notifications::inbox($contract->owner)->markRead($item);

    expect(NotificationReminder::query()->where('type', 'ack.read')->where('kind', 'escalation')->sole()->cancel_reason)->toBe(CancelReason::Acknowledged);
});

it('does not acknowledge on read for a reader who lost access', function (): void {
    Notifications::define('ack.read')->subject(Contract::class)
        ->reminders(Reminder::before('expires_at', 'P1D')->to(Recipients::owner())->acknowledgedOnRead()->escalate('P1D', Recipients::owner()))
        ->title(fn (): string => 'x')->register();
    $contract = expiringContract('2026-11-11 10:00:00');

    at('2026-11-10 10:00:00');
    Notifications::sweep();

    $item = InboxItem::query()->where('type', 'ack.read')->sole();
    $contract->company->users()->detach($contract->owner_id);
    Notifications::inbox($contract->owner)->markRead($item);
    Notifications::inbox($contract->owner)->markAllRead();

    expect($item->fresh()->read_at)->not->toBeNull()
        ->and(NotificationReminder::query()->where('type', 'ack.read')->where('kind', 'escalation')->sole()->status)->toBe(ReminderStatus::Pending);
});

it('absorbs a concurrent sync: never throws on a conflict (C-59b)', function (): void {
    $contract = expiringContract();

    expect(Notifications::for($contract)->reminders()->sync())->toBe(0)
        ->and(fn () => app(SyncRemindersAction::class)->execute($contract))->not->toThrow(Throwable::class);
});

it('never fails the host\'s save when the sync throws (C-59b)', function (): void {
    app()->bind(SyncRemindersAction::class, fn () => throw new RuntimeException('lost race'));

    $contract = expiringContract();

    expect($contract->exists)->toBeTrue()
        ->and(NotificationReminder::query()->count())->toBe(0);
});

it('stores a reminder anchored after 2038 (C-83)', function (): void {
    $contract = expiringContract('2040-06-01 10:00:00');

    expect(pendingDue($contract))->toBe(['-P60D@2040-04-02', '-P30D@2040-05-02', '-P7D@2040-05-25']);
});

it('lists the subject\'s reminders through the trait relation', function (): void {
    $contract = expiringContract();

    expect($contract->notificationReminders()->count())->toBe(3)
        ->and($contract->syncNotificationReminders())->toBe(0)
        ->and(app(TypeRegistry::class)->forSubject($contract))->toHaveCount(1);
});

it('locks the subject\'s active reminders while syncing (C-28)', function (): void {
    $connection = DB::connection();
    $connection->setQueryGrammar(new LockRecordingGrammar($connection));
    LockRecorder::flush();
    LockRecorder::listenForMarkers();

    $contract = expiringContract();
    $contract->update(['expires_at' => CarbonImmutable::parse('2027-03-19 10:00:00', 'UTC')]);

    $locks = array_filter(LockRecorder::recorded(), fn (array $lock): bool => str_contains($lock['sql'], 'notification_reminders'));

    expect($locks)->not->toBeEmpty()
        ->and(min(array_column($locks, 'transactionDepth')))->toBeGreaterThanOrEqual(1);
})->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'the recording grammar is SQLite only');

it('refuses an unreadable or non-positive send-level escalation delay', function (CarbonInterval|string $after): void {
    $approver = user();
    $director = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver')->addMember($director, 'director');

    expect(fn () => Notifications::type('request.awaiting_approval')->about($request)->to($approver)->with(['reference' => 'PR-1'])
        ->escalate(after: $after, to: $director)
        ->send())->toThrow(InvalidReminderRuleException::class, 'is not a positive delay');

    Notifications::sweep();

    expect(NotificationReminder::query()->count())->toBe(0)
        ->and(InboxItem::query()->count())->toBe(0);
    Notification::assertNotSentTo($director, TypedMailNotification::class);
})->with([
    'unreadable' => ['two days'],
    'negative' => ['-P1D'],
    'zero' => ['PT0S'],
    'zero interval' => [fn (): CarbonInterval => CarbonInterval::seconds(0)],
]);

it('refuses an unreadable escalation delay on a reminder rule at build time', function (): void {
    expect(fn () => Reminder::before('expires_at', 'P1D')->escalate('soonish', user()))
        ->toThrow(InvalidReminderRuleException::class, 'The escalation delay "soonish" is not a positive delay');

    app()->setLocale('sk');

    expect(fn () => Reminder::before('expires_at', 'P1D')->escalate(CarbonInterval::seconds(0), user()))
        ->toThrow(InvalidReminderRuleException::class, 'Oneskorenie eskalácie „PT0S“ nie je kladné trvanie');
});

it('refuses an ad-hoc reminder with an escalation, a condition or acknowledgedOnRead', function (Closure $rule): void {
    $contract = contract();
    $manager = user();
    $contract->company->addMember($manager, 'manager');

    expect(fn () => Notifications::for($contract)->remind('contract.expiring', $rule($manager), to: $contract->owner))
        ->toThrow(InvalidReminderRuleException::class, 'ad-hoc reminders take no condition, escalation or acknowledgedOnRead');

    expect(NotificationReminder::query()->where('rule', 'like', 'adhoc:%')->count())->toBe(0);
})->with([
    'escalation' => [fn (User $manager): ReminderRule => Reminder::at(now()->addHour())->escalate('PT1H', $manager)],
    'condition' => [fn (): ReminderRule => Reminder::before('expires_at', 'P1D')->while(fn (): bool => true)],
    'acknowledged on read' => [fn (): ReminderRule => Reminder::at(now()->addHour())->acknowledgedOnRead()],
]);

it('moves an ad-hoc reminder when its own anchor changes', function (): void {
    $contract = expiringContract();
    $contract->forceFill(['terminated_at' => CarbonImmutable::parse('2026-11-19 10:00:00', 'UTC')])->save();
    Notifications::for($contract)->remind('contract.expiring', Reminder::before('terminated_at', 'P1D'), to: $contract->owner);

    $adHoc = fn () => NotificationReminder::query()->where('anchor', 'terminated_at')->where('status', ReminderStatus::Pending->value)->sole();
    expect($adHoc()->due_at->format('Y-m-d'))->toBe('2026-11-18');

    $contract->forceFill(['terminated_at' => CarbonImmutable::parse('2026-11-29 10:00:00', 'UTC')])->save();

    expect($adHoc()->due_at->format('Y-m-d'))->toBe('2026-11-28');
});

it('does nothing on save for a reminder subject with no types', function (): void {
    $fake = Notifications::fake();
    $company = new class extends Model
    {
        use HasNotificationReminders;

        protected $table = 'companies';

        protected $guarded = [];
    };

    $company->forceFill(['name' => 'Typeless'])->save();
    $company->forceFill(['name' => 'Renamed'])->save();

    $fake->assertNoRemindersScheduled();
});

it('skips acknowledge-on-read for a gone reminder or type', function (Closure $break): void {
    Notifications::define('ack.read')->subject(Contract::class)
        ->reminders(Reminder::before('expires_at', 'P1D')->to(Recipients::owner())->acknowledgedOnRead()->escalate('P1D', Recipients::owner()))
        ->title(fn (): string => 'x')->register();
    $contract = expiringContract('2026-11-11 10:00:00');
    at('2026-11-10 10:00:00');
    Notifications::sweep();
    $item = InboxItem::query()->where('type', 'ack.read')->sole();

    $break($item);
    Notifications::inbox($contract->owner)->markRead($item);

    expect($item->fresh()->read_at)->not->toBeNull()
        ->and(NotificationReminder::query()->where('kind', 'escalation')->sole()->status)->toBe(ReminderStatus::Pending);
})->with([
    'reminder row gone' => [fn (InboxItem $item) => NotificationReminder::query()->whereKey($item->reminder_id)->forceDelete()],
    'type gone' => [fn (InboxItem $item) => NotificationReminder::query()->whereKey($item->reminder_id)->update(['type' => 'gone.type'])],
    'rule gone' => [fn (InboxItem $item) => NotificationReminder::query()->whereKey($item->reminder_id)->update(['rule' => 'gone:rule'])],
]);

it('normalises a class-string type to its key in schedule, remind, cancel and acknowledge', function (): void {
    $type = ContractExpiring::class;
    $contract = expiringContract();
    $before = NotificationReminder::query()->count();

    Notifications::for($contract)->reminders()->sync($type);
    Notifications::for($contract)->remind($type, Reminder::at(CarbonImmutable::now('UTC')->addDay()), to: $contract->owner);
    Notifications::for($contract)->remind('contract.expiring', Reminder::at(CarbonImmutable::now('UTC')->addDay()), to: $contract->owner);

    expect(NotificationReminder::query()->count())->toBe($before + 1)
        ->and(NotificationReminder::query()->pluck('type')->unique()->values()->all())->toBe(['contract.expiring'])
        ->and(Notifications::for($contract)->reminders()->pending($type))->toHaveCount($before + 1);

    expect(Notifications::for($contract)->reminders()->cancel($type))->toBe($before + 1);
});

it('normalises a class-string type when scheduling template rules', function (): void {
    $contract = expiringContract();
    Notifications::for($contract)->reminders()->cancel();
    $pending = fn () => NotificationReminder::query()->where('status', ReminderStatus::Pending->value);

    Notifications::for($contract)->reminders()->schedule(ContractExpiring::class, to: $contract->owner);
    Notifications::for($contract)->reminders()->schedule('contract.expiring', to: $contract->owner);

    expect($pending()->count())->toBe(3)
        ->and($pending()->pluck('type')->unique()->values()->all())->toBe(['contract.expiring']);
});

it('skips ad-hoc offsets already in the past', function (): void {
    $contract = expiringContract('2026-11-09 13:00:00');
    Notifications::for($contract)->reminders()->cancel();

    $rows = Notifications::for($contract)->remind('contract.expiring', Reminder::before('expires_at', 'P1D', 'PT2H', 'PT1H'), to: $contract->owner);

    expect(collect($rows)->map(fn (NotificationReminder $row): string => $row->offset.'@'.$row->due_at->format('H:i'))->all())->toBe(['-PT2H@11:00', '-PT1H@12:00'])
        ->and(Notifications::for($contract)->remind('contract.expiring', Reminder::before('expires_at', 'P2D'), to: $contract->owner))->toBe([]);

    at('2026-11-09 11:00:00');
    expect(Notifications::sweep()->reminders)->toBe(1);
});

it('reschedules a reminder cancelled as passed when the anchor moves back', function (): void {
    $contract = expiringContract();      // -P60D 12-19, -P30D 01-18, -P7D 02-10

    $contract->forceFill(['expires_at' => CarbonImmutable::parse('2026-11-29 10:00:00', 'UTC')])->save();
    expect(pendingDue($contract))->toBe(['-P7D@2026-11-22']);

    $contract->forceFill(['expires_at' => CarbonImmutable::parse('2027-02-17 10:00:00', 'UTC')])->save();

    expect(pendingDue($contract))->toBe(['-P60D@2026-12-19', '-P30D@2027-01-18', '-P7D@2027-02-10']);

    at('2026-12-19 10:00:00');
    expect(Notifications::sweep()->reminders)->toBe(1);
});

it('fires an ad-hoc reminder on a type without a subject', function (): void {
    Notifications::define('report.ready')->title(fn (NotificationPayload $p): string => 'Report '.$p->data['id'].' is ready')->register();
    $contract = expiringContract();
    $user = user();

    Notifications::for($contract)->remind('report.ready', Reminder::at(CarbonImmutable::now('UTC')->addDay()), to: $user, data: ['id' => 7]);

    at('2026-11-10 10:00:00');
    expect(Notifications::sweep()->reminders)->toBe(1);

    $item = InboxItem::query()->where('type', 'report.ready')->sole();
    expect($item->recipient_id)->toBe($user->id)
        ->and($item->subject_type)->toBeNull()
        ->and($item->title)->toBe('Report 7 is ready')
        ->and(NotificationReminder::query()->where('type', 'report.ready')->sole()->status)->toBe(ReminderStatus::Sent);
});

it('moves an ad-hoc reminder of a subject-less type when its anchor changes', function (Closure $subject): void {
    Notifications::define('report.ready')->title(fn (): string => 'Report ready')->register();
    $model = $subject();

    Notifications::for($model)->remind('report.ready', Reminder::before('expires_at', 'P1D'), to: user());
    $model->forceFill(['expires_at' => CarbonImmutable::parse('2027-03-17 10:00:00', 'UTC')])->save();

    expect(NotificationReminder::query()->where('type', 'report.ready')->sole()->due_at->format('Y-m-d'))->toBe('2027-03-16');
})->with([
    'a subject with reminder types' => [fn () => expiringContract()],
    'a subject without any type' => [function () {
        $contract = expiringContract();

        return (new class extends Model
        {
            use HasNotificationReminders;

            protected $table = 'contracts';

            protected $guarded = [];

            protected function casts(): array
            {
                return ['expires_at' => 'immutable_datetime'];
            }
        })->newQuery()->findOrFail($contract->id);
    }],
]);

it('keeps every offset of a keyed ad-hoc reminder', function (): void {
    $contract = expiringContract();
    $owner = $contract->owner;
    $rule = fn () => Reminder::before('expires_at', 'P2D', 'P1D')->key('followup');
    $adHoc = fn () => NotificationReminder::query()->where('rule', 'like', 'adhoc:%')->where('status', ReminderStatus::Pending->value)->orderBy('due_at')->get();

    $rows = Notifications::for($contract)->remind('contract.expiring', $rule(), to: $owner);

    expect(collect($rows)->pluck('id')->unique())->toHaveCount(2)
        ->and($adHoc()->map(fn ($row): string => $row->offset.'@'.$row->due_at->format('Y-m-d'))->all())->toBe(['-P2D@2027-02-15', '-P1D@2027-02-16']);

    Notifications::for($contract)->remind('contract.expiring', $rule(), to: $owner);

    expect($adHoc())->toHaveCount(2);

    Notifications::for($contract)->remind('contract.expiring', Reminder::before('expires_at', 'P3D')->key('single'), to: $owner);
    Notifications::for($contract)->remind('contract.expiring', Reminder::before('expires_at', 'P4D')->key('single'), to: $owner);

    expect($adHoc())->toHaveCount(3);
});

it('does not leave an orphan that fires twice when remind() replaces a firing row', function (): void {
    $contract = expiringContract();
    $owner = $contract->owner;
    $remind = fn (string $at) => Notifications::for($contract)->remind('contract.expiring', Reminder::at(CarbonImmutable::parse($at, 'UTC'))->key('follow-up'), to: $owner);
    $pending = fn () => NotificationReminder::query()->where('rule', 'adhoc:follow-up')->where('status', ReminderStatus::Pending->value);

    [$first] = $remind('2026-11-09 12:00:00');
    $first->forceFill(['status' => ReminderStatus::Firing])->save();     // the sweep is firing it

    $remind('2026-11-10 10:00:00');
    $remind('2026-11-10 11:00:00');
    expect($pending()->count())->toBe(1);

    $first->forceFill(['status' => ReminderStatus::Sent, 'active_key' => null])->save();   // the fire closes it
    $remind('2026-11-11 10:00:00');

    expect($pending()->count())->toBe(1)
        ->and($pending()->sole()->active_key)->not->toBeNull()
        ->and($pending()->sole()->due_at->format('Y-m-d H:i'))->toBe('2026-11-11 10:00');
});

it('does not leave an orphan when schedule() runs during a fire of the same offset', function (): void {
    Notifications::define('task.due')->subject(Contract::class)->reminders(Reminder::before('expires_at', 'P1D'))->title(fn (): string => 'Due')->register();
    $contract = expiringContract();
    $pending = fn () => NotificationReminder::query()->where('type', 'task.due')->where('status', ReminderStatus::Pending->value);

    [$first] = Notifications::for($contract)->reminders()->schedule('task.due', to: $contract->owner);
    $first->forceFill(['status' => ReminderStatus::Firing])->save();
    Notifications::for($contract)->reminders()->schedule('task.due', to: $contract->owner);
    $first->forceFill(['status' => ReminderStatus::Sent, 'active_key' => null])->save();
    Notifications::for($contract)->reminders()->schedule('task.due', to: $contract->owner);

    expect($pending()->count())->toBe(1);
});

it('arms no next level when the subject is acknowledged while a level fires', function (): void {
    $approver = user();
    $director = user();
    $board = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver')->addMember($director, 'director')->addMember($board, 'board');
    Notifications::type('request.awaiting_approval')->about($request)->to($approver)->with(['reference' => 'PR-1'])
        ->escalate(after: 'P1D', to: $director)
        ->escalate(after: 'P1D', to: $board)
        ->send();

    // The director's client reacts to the pushed item while the level is still firing.
    Event::listen(InboxItemCreated::class, function (InboxItemCreated $event) use ($director, $request): void {
        if ($event->item->recipient_id === $director->id) {
            Notifications::for($request)->acknowledge(by: $director);
        }
    });

    at('2026-11-10 10:00:00');
    Notifications::sweep();
    at('2026-11-11 10:00:00');
    Notifications::sweep();

    Notification::assertNotSentTo($board, TypedMailNotification::class);
    expect(NotificationReminder::query()->where('kind', ReminderKind::Escalation->value)->where('status', ReminderStatus::Pending->value)->count())->toBe(0);
});
