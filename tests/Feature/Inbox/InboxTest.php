<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Notifications\Context;
use RoundlyConsulting\Notifications\Enums\InboxOutcome;
use RoundlyConsulting\Notifications\Events\InboxItemArchived;
use RoundlyConsulting\Notifications\Events\InboxItemCreated;
use RoundlyConsulting\Notifications\Events\InboxItemsRead;
use RoundlyConsulting\Notifications\Events\InboxItemUnarchived;
use RoundlyConsulting\Notifications\Events\InboxItemUpdated;
use RoundlyConsulting\Notifications\Exceptions\InboxItemNotFoundException;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationDataException;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Handles\InboxHandle;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Company;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\PurchaseRequest;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;

beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 10:00:00');
});

/**
 * @return array{0: PurchaseRequest, 1: User}
 */
function approvalSetup(): array
{
    $approver = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');

    return [$request, $approver];
}

it('collapses events of one subject within the window into one item with a count', function (): void {
    Event::fake([InboxItemCreated::class, InboxItemUpdated::class]);
    [$request, $approver] = approvalSetup();
    $actors = [user(), user()];

    foreach (range(1, 10) as $i) {
        at('2026-11-09 10:0'.min(4, intdiv($i, 3)).':'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'PR-'.$i], actor: $actors[$i % 2]);
    }

    $item = InboxItem::query()->sole();

    expect($item->count)->toBe(10)
        ->and($item->data)->toBe(['reference' => 'PR-10'])
        ->and($item->actor_id)->toBe($actors[0]->id)
        ->and($item->title)->toBe('Request PR-10 awaits your approval (10)')
        ->and($item->context_key)->toBe('company:'.$request->company_id)
        ->and($item->context_label)->toBe('Acme s.r.o.');

    Event::assertDispatchedTimes(InboxItemCreated::class, 1);
    Event::assertDispatched(InboxItemUpdated::class, fn (InboxItemUpdated $event): bool => $event->previousCount === 9 && $event->item->count === 10);
});

it('starts a new item after the window, anchored at the first event (C-36)', function (): void {
    [$request, $approver] = approvalSetup();

    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);
    at('2026-11-09 10:04:59');
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'B']);
    at('2026-11-09 10:05:00');
    $result = Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'C']);

    expect($result->outcomes[0]->inbox)->toBe(InboxOutcome::Created)
        ->and(InboxItem::query()->orderBy('id')->pluck('count')->all())->toBe([2, 1])
        ->and(InboxItem::query()->orderBy('id')->first()->open_key)->toBeNull();
});

it('makes a read item unread again on a new event (C-36b)', function (): void {
    [$request, $approver] = approvalSetup();

    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);
    Notifications::inbox($approver)->markAllRead();
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'B']);

    expect(InboxItem::query()->sole()->read_at)->toBeNull()
        ->and(Notifications::inbox($approver)->unreadCount())->toBe(1);
});

it('starts a new item after archiving (C-36b), and an unarchived item stays closed', function (): void {
    [$request, $approver] = approvalSetup();

    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);
    $first = InboxItem::query()->sole();
    Notifications::inbox($approver)->archive($first)->unarchive($first);

    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'B']);

    expect(InboxItem::query()->count())->toBe(2)
        ->and($first->fresh()->open_key)->toBeNull()
        ->and($first->fresh()->count)->toBe(1);
});

it('never collapses a type without a window (C-26)', function (): void {
    $user = user();

    Notifications::send('security.alert', null, $user);
    Notifications::send('security.alert', null, $user);

    expect(InboxItem::query()->count())->toBe(2)
        ->and(InboxItem::query()->whereNotNull('open_key')->count())->toBe(0)
        ->and(NotificationDelivery::query()->count())->toBe(2);
});

it('keeps the same subject in two contexts as two items', function (): void {
    [$request, $approver] = approvalSetup();

    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A'], context: 'one');
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A'], context: 'two');

    expect(InboxItem::query()->count())->toBe(2)->and(NotificationDelivery::query()->count())->toBe(2);
});

it('never lets a deleted, revoked or archived item block the next event (C-75)', function (): void {
    [$request, $approver] = approvalSetup();

    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);
    Notifications::inbox($approver)->delete(InboxItem::query()->sole());
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'B']);

    Notifications::for($request)->revoke($approver);
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'C']);

    // A row trashed by hand still owns its key: the lookup sees it and closes it.
    InboxItem::query()->sole()->delete();
    $result = Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'D']);

    expect($result->outcomes[0]->inbox)->toBe(InboxOutcome::Created)
        ->and(InboxItem::query()->count())->toBe(1)
        ->and(InboxItem::withTrashed()->count())->toBe(4);
});

it('replays nothing for an idempotency key it already delivered (C-70)', function (): void {
    $user = user();

    $first = Notifications::type('security.alert')->to($user)->once('login:42')->send();
    $second = Notifications::type('security.alert')->to($user)->once('login:42')->send();

    expect($first->outcomes[0]->inbox)->toBe(InboxOutcome::Created)
        ->and($second->outcomes[0]->inbox)->toBe(InboxOutcome::Duplicate)
        ->and($second->skipped())->toHaveCount(1)
        ->and(InboxItem::query()->count())->toBe(1);
});

it('never puts a class name in a model context key (C-76)', function (): void {
    [$request, $approver] = approvalSetup();

    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);
    Relation::morphMap(['org' => Company::class]);
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A'], context: Context::of($request->company, 'Custom label'));
    Relation::morphMap([], merge: false);

    expect(InboxItem::query()->orderBy('id')->pluck('context_key')->all())->toBe(['company:'.$request->company_id, 'org:'.$request->company_id])
        ->and(InboxItem::query()->orderBy('id')->pluck('context_label')->all())->toBe(['Acme s.r.o.', 'Custom label']);
});

it('refuses a string context that looks like a class name', function (): void {
    Notifications::send('security.alert', null, user(), context: 'App\\Models\\Team');
})->throws(InvalidNotificationDataException::class);

it('labels a model context by its name, else its basename and key', function (): void {
    $user = user();
    $other = user(['name' => null]);

    Notifications::send('security.alert', null, $user, context: Context::of($other));
    Notifications::send('security.alert', null, $user, context: Context::key('billing', 'Billing'));

    expect(InboxItem::query()->orderBy('id')->pluck('context_label')->all())->toBe(['User '.$other->id, 'Billing']);
});

it('filters one context, items without one, a category, a type and a date; and lists every context by default', function (): void {
    [$request, $approver] = approvalSetup();
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);
    at('2026-11-09 11:00:00');
    Notifications::send('security.alert', null, $approver);

    $inbox = Notifications::inbox($approver);

    expect($inbox->paginate()->total())->toBe(2)
        ->and($inbox->in($request->company)->get())->toHaveCount(1)
        ->and($inbox->in('company:'.$request->company_id)->allContexts()->get())->toHaveCount(2)
        ->and($inbox->withoutContext()->first()->type)->toBe('security.alert')
        ->and($inbox->category('requests')->get())->toHaveCount(1)
        ->and($inbox->type('security.alert')->get())->toHaveCount(1)
        ->and($inbox->since(now()->subMinutes(30))->get())->toHaveCount(1)
        ->and($inbox->latest()->first()->type)->toBe('security.alert')
        ->and($inbox->cursorPaginate(1)->count())->toBe(1);
});

it('pages, counts and marks read, unread, archived and deleted items', function (): void {
    Event::fake([InboxItemsRead::class, InboxItemArchived::class, InboxItemUnarchived::class]);
    [$request, $approver] = approvalSetup();
    $other = purchaseRequest(company: $request->company, reference: 'PR-2');

    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);
    Notifications::send('request.awaiting_approval', $other, $approver, ['reference' => 'B']);
    Notifications::send('security.alert', null, $approver);

    $inbox = Notifications::inbox($approver);
    [$first, $second, $third] = InboxItem::query()->orderBy('id')->get()->all();

    expect($inbox->unreadCount())->toBe(3)
        ->and($approver->unreadNotificationCount())->toBe(3);

    $inbox->markRead($first)->markRead($first);
    expect($inbox->read()->get())->toHaveCount(1)->and($inbox->unread()->get())->toHaveCount(2);

    $inbox->markUnread($first)->markUnread($first);
    expect($inbox->unreadCount())->toBe(3);

    expect($inbox->markAllRead(in: $request->company))->toBe(2)
        ->and($inbox->markAllRead(category: 'security'))->toBe(1)
        ->and($inbox->markAllRead())->toBe(0);

    $inbox->archive($second)->archive($second);
    expect($inbox->archived()->get())->toHaveCount(1)
        ->and($inbox->withArchived()->get())->toHaveCount(3)
        ->and($inbox->paginate(perPage: 500)->perPage())->toBe(100);

    $inbox->unarchive($second)->delete($third);
    expect($inbox->get())->toHaveCount(2);

    $counts = $inbox->counts();
    expect($counts->unread)->toBe(0)
        ->and($counts->total)->toBe(2);

    Event::assertDispatched(InboxItemsRead::class, fn (InboxItemsRead $event): bool => count($event->ids) === 2);
    Event::assertDispatchedTimes(InboxItemArchived::class, 1);
    Event::assertDispatchedTimes(InboxItemUnarchived::class, 1);
});

it('counts unread per context with the newest label', function (): void {
    [$request, $approver] = approvalSetup();
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);
    Notifications::send('security.alert', null, $approver, context: Context::key('billing', 'Billing'));
    Notifications::send('security.alert', null, $approver);

    $counts = Notifications::inbox($approver)->counts();

    expect($counts->unread)->toBe(3)
        ->and($counts->total)->toBe(3)
        ->and(collect($counts->contexts)->mapWithKeys(fn ($c) => [$c->key => [$c->label, $c->unread]])->all())->toBe([
            'billing' => ['Billing', 1],
            'company:'.$request->company_id => ['Acme s.r.o.', 1],
        ]);
});

it('treats another user\'s item as not found (C-32)', function (Closure $call): void {
    $owner = user();
    Notifications::send('security.alert', null, $owner);
    $item = InboxItem::query()->sole();

    expect(fn () => $call(Notifications::inbox(user()), $item))->toThrow(InboxItemNotFoundException::class, 'The notification was not found.');
})->with([
    'find' => [fn ($inbox, $item) => $inbox->find($item)],
    'find by id' => [fn ($inbox, $item) => $inbox->find($item->id)],
    'mark read' => [fn ($inbox, $item) => $inbox->markRead($item)],
    'mark unread' => [fn ($inbox, $item) => $inbox->markUnread($item->id)],
    'archive' => [fn ($inbox, $item) => $inbox->archive($item)],
    'unarchive' => [fn ($inbox, $item) => $inbox->unarchive($item)],
    'delete' => [fn ($inbox, $item) => $inbox->delete($item)],
    'a junk id' => [fn ($inbox, $item) => $inbox->find('1 or 1=1')],
]);

it('loads a page of items with a fixed number of queries (C-35)', function (): void {
    [$request, $approver] = approvalSetup();

    foreach (range(1, 3) as $i) {
        Notifications::send('request.awaiting_approval', purchaseRequest(company: $request->company, reference: 'R'.$i), $approver, ['reference' => 'R'.$i], actor: user());
    }

    DB::enableQueryLog();
    Notifications::inbox($approver)->paginate()->each(fn (InboxItem $item) => [$item->actor?->getKey(), $item->subject?->getKey()]);
    $few = count(DB::getQueryLog());

    foreach (range(4, 9) as $i) {
        Notifications::send('request.awaiting_approval', purchaseRequest(company: $request->company, reference: 'R'.$i), $approver, ['reference' => 'R'.$i], actor: user());
    }

    DB::flushQueryLog();
    Notifications::inbox($approver)->paginate()->each(fn (InboxItem $item) => [$item->actor?->getKey(), $item->subject?->getKey()]);

    expect(count(DB::getQueryLog()))->toBe($few);
});

it('locks the open item while collapsing (C-28)', function (): void {
    $connection = DB::connection();
    $connection->setQueryGrammar(new LockRecordingGrammar($connection));
    LockRecorder::flush();
    LockRecorder::listenForMarkers();

    [$request, $approver] = approvalSetup();
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);

    $locks = array_filter(LockRecorder::recorded(), fn (array $lock): bool => str_contains($lock['sql'], 'notification_inbox_items') || str_contains($lock['sql'], 'notification_deliveries'));

    expect($locks)->toHaveCount(2)
        ->and(min(array_column($locks, 'transactionDepth')))->toBeGreaterThanOrEqual(2);
})->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'the recording grammar is SQLite only');

it('revokes a recipient\'s items and pending mail for a subject (C-71)', function (): void {
    config()->set('notifications.quiet_hours.default', ['start' => '00:00', 'end' => '23:59']);
    [$request, $approver] = approvalSetup();
    $other = user();
    $request->company->addMember($other, 'approver');

    Notifications::send('request.awaiting_approval', $request, Recipients::role($request->company, 'approver'), ['reference' => 'A']);

    expect(Notifications::for($request)->revoke($approver))->toBe(2)
        ->and(Notifications::inbox($approver)->unreadCount())->toBe(0)
        ->and(Notifications::inbox($other)->unreadCount())->toBe(1)
        ->and(NotificationDelivery::query()->where('cancel_reason', 'revoked')->count())->toBe(1)
        ->and(Notifications::for($request)->revoke())->toBe(2);
});

it('hides items the reader lost access to when recheck on read is on', function (): void {
    config()->set('notifications.inbox.recheck_on_read', true);
    [$request, $approver] = approvalSetup();
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);
    Notifications::send('security.alert', null, $approver);

    DB::table('company_user')->where('user_id', $approver->id)->delete();

    expect(Notifications::inbox($approver)->get())->toHaveCount(1)
        ->and(Notifications::inbox($approver)->paginate()->count())->toBe(1)
        ->and(Notifications::inbox($approver)->cursorPaginate()->count())->toBe(1)
        ->and(Notifications::inbox($approver)->unreadCount())->toBe(2);
});

it('rechecks access on read without one query per item', function (): void {
    $reader = user();
    $author = user();

    foreach (range(1, 10) as $n) {
        Notifications::send('comment.posted', post($author), $reader, ['title' => "Post {$n}"]);
    }

    $queries = function (bool $recheck) use ($reader): int {
        config()->set('notifications.inbox.recheck_on_read', $recheck);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $items = Notifications::inbox($reader)->get();
        DB::disableQueryLog();

        expect($items)->toHaveCount(10);

        return count(DB::getQueryLog());
    };

    expect($queries(true))->toBe($queries(false));
});

it('hides a trashed subject on recheck unless the type allows trashed subjects', function (): void {
    config()->set('notifications.inbox.recheck_on_read', true);
    Notifications::define('post.archived')->subject(Post::class, allowsTrashed: true)->title(fn (): string => 'x')->register();
    $reader = user();
    $kept = post();
    $hidden = post();
    Notifications::send('post.archived', $kept, $reader);
    Notifications::send('comment.posted', $hidden, $reader, ['title' => 'x']);

    $kept->delete();
    $hidden->delete();

    expect(Notifications::inbox($reader)->get()->pluck('type')->all())->toBe(['post.archived']);
});

it('archives or deletes items when their subject is deleted, as configured', function (string $mode, int $active, int $archived): void {
    config()->set('notifications.inbox.on_subject_deleted', $mode);
    $owner = user();
    $contract = contract(owner: $owner);
    Notifications::send('contract.expiring', $contract, $owner);

    $contract->delete();

    expect(Notifications::inbox($owner)->get())->toHaveCount($active)
        ->and(Notifications::inbox($owner)->archived()->get())->toHaveCount($archived);
})->with([
    'keep' => ['keep', 1, 0],
    'archive' => ['archive', 0, 1],
    'delete' => ['delete', 0, 0],
]);

it('composes ReceivesNotifications with Laravel\'s Notifiable (C-60)', function (): void {
    $user = user();
    Notifications::send('security.alert', null, $user);

    expect($user->inbox()->unreadCount())->toBe(1)
        ->and($user->notificationPreferences()->muted('security.alert'))->toBeFalse()
        ->and(method_exists($user, 'notifications'))->toBeTrue()
        ->and(method_exists($user, 'notify'))->toBeTrue();
});

/**
 * Two companies' requests (contexts) for one approver, plus a context-less security alert an hour later.
 *
 * @return array{0: User, 1: PurchaseRequest, 2: PurchaseRequest}
 */
function twoContextInbox(): array
{
    $approver = user();
    $a = purchaseRequest(company('A'));
    $b = purchaseRequest(company('B'), reference: 'PR-2');
    $a->company->addMember($approver, 'approver');
    $b->company->addMember($approver, 'approver');

    Notifications::send('request.awaiting_approval', $a, $approver, ['reference' => 'PR-1']);
    Notifications::send('request.awaiting_approval', $b, $approver, ['reference' => 'PR-2']);
    at('2026-11-09 11:00:00');
    Notifications::send('security.alert', null, $approver);

    return [$approver, $a, $b];
}

it('marks only the filtered context read through in()->markAllRead()', function (): void {
    [$approver, $a, $b] = twoContextInbox();

    $marked = Notifications::inbox($approver)->in($a->company)->markAllRead();

    expect($marked)->toBe(1)
        ->and(Notifications::inbox($approver)->in($a->company)->unreadCount())->toBe(0)
        ->and(Notifications::inbox($approver)->in($b->company)->unreadCount())->toBe(1)
        ->and(Notifications::inbox($approver)->unreadCount())->toBe(2);
});

it('honours category(), type(), withoutContext() and since() in markAllRead()', function (Closure $filter, int $marked, int $left): void {
    [$approver] = twoContextInbox();

    expect($filter(Notifications::inbox($approver))->markAllRead())->toBe($marked)
        ->and(Notifications::inbox($approver)->unreadCount())->toBe($left);
})->with([
    'category' => [fn (InboxHandle $inbox): InboxHandle => $inbox->category('requests'), 2, 1],
    'type' => [fn (InboxHandle $inbox): InboxHandle => $inbox->type('security.alert'), 1, 2],
    'without a context' => [fn (InboxHandle $inbox): InboxHandle => $inbox->withoutContext(), 1, 2],
    'since' => [fn (InboxHandle $inbox): InboxHandle => $inbox->since(now()->subMinutes(30)), 1, 2],
]);

it('lets an explicit markAllRead(in:) argument override the handle context', function (): void {
    [$approver, $a, $b] = twoContextInbox();

    expect(Notifications::inbox($approver)->in($a->company)->markAllRead(in: $b->company))->toBe(1)
        ->and(Notifications::inbox($approver)->in($a->company)->unreadCount())->toBe(1)
        ->and(Notifications::inbox($approver)->in($b->company)->unreadCount())->toBe(0)
        ->and(Notifications::inbox($approver)->category('requests')->markAllRead(category: 'security'))->toBe(1)
        ->and(Notifications::inbox($approver)->unreadCount())->toBe(1);
});
