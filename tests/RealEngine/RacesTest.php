<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\Actions\Interop\ApplyInteropRulesAction;
use RoundlyConsulting\Notifications\Actions\Reminders\SyncRemindersAction;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Jobs\DeliverMailJob;
use RoundlyConsulting\Notifications\Mail\TypedMailNotification;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Support\DeliveryStates;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Contract;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\PurchaseRequest;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications\VendorThingHappened;
use RoundlyConsulting\Notifications\Tests\Support\Racer;

/**
 * Real-engine races (PostgreSQL and MySQL legs): a second session forked while this one holds an
 * uncommitted write, so the unique "open keys", the compare-and-set claims and the savepoint
 * retries meet a real lock. SQLite has no second session: these skip there.
 */
beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 10:00:00');
});

it('makes one inbox item and one delivery from two racing first events, the loser\'s transaction still usable (C-74)', function (): void {
    $approver = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');

    DB::beginTransaction();
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'parent']);

    $racer = (new Racer)->run(function () use ($request, $approver): string {
        return DB::transaction(function () use ($request, $approver): string {
            $result = Notifications::send('request.awaiting_approval', PurchaseRequest::query()->findOrFail($request->id), User::query()->findOrFail($approver->id), ['reference' => 'racer']);

            // The surrounding transaction is still usable after the lost insert race.
            $usable = DB::selectOne('select 1 as ok')->ok;

            return $result->outcomes[0]->inbox->value.'/'.$result->outcomes[0]->mail->value.'/'.$usable;
        });
    })->waitUntilBlockedOrDone();

    DB::commit();

    expect($racer->outcome())->toBeIn(['collapsed/folded/1', 'collapsed/deduplicated/1'])
        ->and(InboxItem::query()->sole()->count)->toBe(2)
        ->and(NotificationDelivery::query()->sole()->events_count)->toBe(2);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl');

it('sends a due delivery once when two sweeps race for it (C-27)', function (): void {
    $user = user();
    $delivery = NotificationDelivery::factory()->recipient($user)->create(['type' => 'security.alert']);

    DB::beginTransaction();
    expect(DeliveryStates::claim($delivery->id, NotificationDelivery::SOURCE_TYPE))->toBeTrue();

    $racer = (new Racer)->run(function () use ($delivery): string {
        app()->call([new DeliverMailJob($delivery->id), 'handle']);

        try {
            Notification::assertNothingSent();

            return 'nothing';
        } catch (Throwable) {
            return 'sent';
        }
    })->waitUntilBlockedOrDone();

    DB::commit();

    expect($racer->outcome())->toBe('nothing')
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::Sending);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl');

it('makes one set of pending reminders from two racing syncs', function (): void {
    Notifications::fake();
    $contract = contract(expiresAt: CarbonImmutable::parse('2027-02-17 10:00:00', 'UTC'));
    $notifications = app(SyncRemindersAction::class);

    DB::beginTransaction();
    expect($notifications->execute($contract))->toBe(3);

    $racer = (new Racer)->run(function () use ($contract): string {
        return (string) app(SyncRemindersAction::class)->execute(Contract::query()->findOrFail($contract->id));
    })->waitUntilBlockedOrDone();

    DB::commit();

    expect($racer->outcome())->toBeIn(['0', '3'])
        ->and(NotificationReminder::query()->where('status', 'pending')->count())->toBe(3);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl');

it('sends one mail when two workers deliver mapped notifications of one key (C-82)', function (): void {
    config()->set('notifications.interop.map', [VendorThingHappened::class => 'vendor.thing']);
    $user = user();
    $post = post();

    DB::beginTransaction();
    $first = new VendorThingHappened($post);
    $first->id = (string) Str::uuid();
    expect(app(ApplyInteropRulesAction::class)->execute($user, $first, 'mail'))->toBeNull();

    $racer = (new Racer)->run(function () use ($user, $post): string {
        $second = new VendorThingHappened(Post::query()->findOrFail($post->id));
        $second->id = (string) Str::uuid();

        return var_export(app(ApplyInteropRulesAction::class)->execute(User::query()->findOrFail($user->id), $second, 'mail'), true);
    })->waitUntilBlockedOrDone();

    DB::commit();

    expect($racer->outcome())->toBe('false')
        ->and(NotificationDelivery::query()->sole()->events_count)->toBe(2);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl');

it('surfaces a deadlock inside a host transaction to the host, and retries it at the top level (C-74)', function (): void {
    $attempts = 0;
    InboxItem::creating(function () use (&$attempts): never {
        $attempts++;

        throw new QueryException('testing', 'insert', [], new PDOException('Deadlock found when trying to get lock; try restarting transaction'));
    });

    expect(fn () => DB::transaction(fn () => Notifications::send('security.alert', null, user())))
        ->toThrow(DeadlockException::class)
        ->and($attempts)->toBe(1);

    $attempts = 0;

    expect(fn () => Notifications::send('security.alert', null, user()))->toThrow(DeadlockException::class)
        ->and($attempts)->toBe(3);

    Notification::assertNotSentTo(User::query()->first(), TypedMailNotification::class);
});

it('cancels the next escalation level when an acknowledgement races the level\'s final write (F-4)', function (): void {
    $approver = user();
    $director = user();
    $board = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver')->addMember($director, 'director')->addMember($board, 'board');
    Notifications::type('request.awaiting_approval')->about($request)->to($approver)->with(['reference' => 'PR-1'])
        ->escalate(after: 'P1D', to: $director)
        ->escalate(after: 'P1D', to: $board)
        ->send();

    $racer = null;

    // Right after the fire step reads whether the level was acknowledged — inside its final
    // transaction, before it arms the next level — a second session acknowledges the subject.
    DB::listen(function ($query) use (&$racer, $request): void {
        if ($racer !== null || ! str_contains($query->sql, 'acknowledged_at') || ! str_contains(strtolower($query->sql), 'for update')) {
            return;
        }

        $racer = (new Racer)->run(fn (): string => 'acknowledged:'.Notifications::for(PurchaseRequest::query()->findOrFail($request->id))->acknowledge())
            ->waitUntilBlockedOrDone();
    });

    at('2026-11-10 10:00:00');
    Notifications::sweep();

    expect($racer)->not->toBeNull()
        ->and($racer?->outcome())->toStartWith('acknowledged:')
        ->and(NotificationReminder::query()->where('level', 2)->sole()->status->value)->toBe('cancelled');
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl');
