<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Notifications\Actions\Sending\SendNotificationAction;
use RoundlyConsulting\Notifications\Builders\PendingNotification;
use RoundlyConsulting\Notifications\DataTransferObjects\PreferencesUpdate;
use RoundlyConsulting\Notifications\DataTransferObjects\SendNotificationData;
use RoundlyConsulting\Notifications\DataTransferObjects\SendResult;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Reminders\Reminder;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Testing\NotificationsFake;

it('pins the facade contract', function (): void {
    expect(Notifications::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

it('registers no global alias (one letter from Laravel\'s Notification facade)', function (): void {
    /** @var array{extra: array{laravel: array<string, mixed>}} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['extra']['laravel'])->not->toHaveKey('aliases');
});

it('runs one send three ways: facade, injected manager, raw action', function (): void {
    $user = user();

    $viaFacade = Notifications::send('security.alert', null, $user, ['what' => 'login']);
    $viaManager = app(NotificationsManager::class)->send('security.alert', null, $user, ['what' => 'password']);
    $viaAction = app(SendNotificationAction::class)->execute(new SendNotificationData(
        type: 'security.alert',
        subject: null,
        recipients: Recipients::users($user),
        data: ['what' => 'email'],
    ));

    expect(app(NotificationsManager::class))->toBe(app(NotificationsManager::class))
        ->and([$viaFacade, $viaManager, $viaAction])->each->toBeInstanceOf(SendResult::class)
        ->and(Notifications::inbox($user)->unreadCount())->toBe(3);
});

it('resolves actions through the container so a host override applies', function (): void {
    app()->bind(SendNotificationAction::class, static fn (): never => throw new RuntimeException('overridden'));

    Notifications::send('security.alert', null, user());
})->throws(RuntimeException::class, 'overridden');

it('reaches every accessor and handle through the facade', function (): void {
    $user = user();
    $contract = contract();

    expect(Notifications::type('security.alert'))->toBeInstanceOf(PendingNotification::class)
        ->and(Notifications::types()->has('security.alert'))->toBeTrue()
        ->and(Notifications::types()->find('security.alert')->mandatory)->toBeTrue()
        ->and(Notifications::types()->get('security.alert')->key())->toBe('security.alert')
        ->and(Notifications::types()->categories())->not->toBeEmpty()
        ->and(Notifications::for($contract)->subject()->is($contract))->toBeTrue()
        ->and(Notifications::inbox($user)->recipient()->is($user))->toBeTrue()
        ->and(Notifications::preferences($user)->user()->is($user))->toBeTrue()
        ->and(Notifications::reminders()->fireDue()->reminders)->toBe(0)
        ->and(Notifications::digests()->sendDue())->toBe(0)
        ->and(Notifications::digests()->preview($user))->toBeNull()
        ->and(Notifications::deliveries()->dispatchDue())->toBe(0)
        ->and(Notifications::interop()->mapped())->toBe([])
        ->and(Notifications::sweep()->deliveries)->toBe(0)
        ->and(Notifications::prune()->total())->toBe(0);
});

describe('the fake', function (): void {
    it('is record-only: nothing is written, nothing is mailed', function (): void {
        $fake = Notifications::fake();
        $user = user();

        Notifications::send('security.alert', null, $user);
        Notifications::type('security.alert')->to($user)->send();

        expect(app(NotificationsManager::class))->toBeInstanceOf(NotificationsFake::class)
            ->and(InboxItem::query()->count())->toBe(0);

        $fake->assertSent('security.alert', to: $user, times: 2);
    });

    it('records a send made through the raw DTO entry point', function (): void {
        $fake = Notifications::fake();
        $user = user();

        Notifications::sendNotification(new SendNotificationData('security.alert', null, Recipients::users($user)));

        $fake->assertSent('security.alert', to: $user);
        expect($fake->recorded(NotificationOperation::Send))->toHaveCount(1);
    });

    it('resolves recipients so assertSent works with a role', function (): void {
        $fake = Notifications::fake();
        $approver = user();
        $request = purchaseRequest();
        $request->company->addMember($approver, 'approver');

        Notifications::send('request.awaiting_approval', $request, Recipients::role($request->company, 'approver'));

        $fake->assertSent('request.awaiting_approval', to: $approver, about: $request);
        $fake->assertSent('request.awaiting_approval', to: fn ($recipient): bool => $recipient->is($approver));
        $fake->assertNotSent('security.alert');
    });

    it('fails assertSent when nothing matches', function (): void {
        Notifications::fake()->assertSent('security.alert');
    })->throws(ExpectationFailedException::class, 'Expected [security.alert] to be sent, but no send matched.');

    it('fails assertSent with the wrong count', function (): void {
        $fake = Notifications::fake();
        Notifications::send('security.alert', null, user());

        $fake->assertSent('security.alert', times: 2);
    })->throws(ExpectationFailedException::class, 'to be sent 2 time(s), but it was sent 1 time(s)');

    it('fails assertSent for another subject or recipient', function (): void {
        $fake = Notifications::fake();
        $request = purchaseRequest();
        Notifications::send('request.awaiting_approval', $request, user());

        expect(fn () => $fake->assertSent('request.awaiting_approval', about: purchaseRequest()))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $fake->assertSent('request.awaiting_approval', to: user()))->toThrow(ExpectationFailedException::class);
    });

    it('fails assertNotSent and assertNothingSent after a send', function (): void {
        $fake = Notifications::fake();
        Notifications::send('security.alert', null, user());

        expect(fn () => $fake->assertNotSent('security.alert'))->toThrow(ExpectationFailedException::class, 'not to be sent')
            ->and(fn () => $fake->assertNothingSent())->toThrow(ExpectationFailedException::class, '1 send(s) were recorded');
    });

    it('passes assertNothingSent on an untouched fake', function (): void {
        Notifications::fake()->assertNothingSent();
    });

    it('records inbox mutations, including calls through the model trait', function (): void {
        $fake = Notifications::fake();
        $user = user();

        $user->inbox()->markRead(1);
        Notifications::inbox($user)->archive(2);
        Notifications::inbox($user)->markAllRead();

        $fake->assertMarkedRead($user);
        $fake->assertArchived($user);
        expect(fn () => $fake->assertMarkedRead(user()))->toThrow(ExpectationFailedException::class, 'marked read')
            ->and(fn () => $fake->assertArchived(user()))->toThrow(ExpectationFailedException::class, 'archived');
    });

    it('records preference writes, including calls through the model trait', function (): void {
        $fake = Notifications::fake();
        $user = user();

        $user->notificationPreferences()->mute('comment.posted');
        Notifications::preferences($user)->set('request.awaiting_approval', Channel::Mail, true);

        $fake->assertMuted($user, 'comment.posted');
        $fake->assertPreferenceSet($user, 'request.awaiting_approval', Channel::Mail, true);

        expect(fn () => $fake->assertMuted($user, 'request.awaiting_approval'))->toThrow(ExpectationFailedException::class, 'to be muted')
            ->and(fn () => $fake->assertPreferenceSet($user, 'comment.posted', Channel::Mail, true))->toThrow(ExpectationFailedException::class, 'to be set on');
    });

    it('records a preferences update, including one through the model trait', function (): void {
        $fake = Notifications::fake();
        $user = user();
        $other = user();
        $update = new PreferencesUpdate(channels: ['comment.posted' => ['mail' => false]], setsTimezone: true, timezone: 'Europe/Bratislava');

        $user->notificationPreferences()->update($update);

        $fake->assertPreferencesUpdated($user);
        $fake->assertPreferencesUpdated($user, fn (PreferencesUpdate $recorded): bool => $recorded->timezone === 'Europe/Bratislava');
        expect(Preferences::channelMap($user))->toBe(['types' => [], 'categories' => []])
            ->and(fn () => $fake->assertPreferencesUpdated($other))->toThrow(ExpectationFailedException::class, 'preferences to be updated')
            ->and(fn () => $fake->assertPreferencesUpdated($user, fn (PreferencesUpdate $recorded): bool => $recorded->setsQuietHours))->toThrow(ExpectationFailedException::class, 'preferences to be updated');
    });

    it('records reminder operations, including the trait sync on save', function (): void {
        $fake = Notifications::fake();
        $contract = contract();

        Notifications::for($contract)->reminders()->sync();
        Notifications::for($contract)->remind('contract.expiring', Reminder::at(CarbonImmutable::now()->addDay()), to: user());
        Notifications::for($contract)->reminders()->cancel();
        Notifications::for($contract)->acknowledge('contract.expiring');
        Notifications::for($contract)->revoke();

        $fake->assertRemindersSynced($contract);
        $fake->assertReminderScheduled($contract, 'contract.expiring');
        $fake->assertRemindersCancelled($contract);
        $fake->assertAcknowledged($contract, 'contract.expiring');
        $fake->assertRevoked($contract);

        $other = contract();
        expect(fn () => $fake->assertRemindersCancelled($other))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $fake->assertAcknowledged($other))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $fake->assertRevoked($other))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $fake->assertReminderScheduled($contract, 'security.alert'))->toThrow(ExpectationFailedException::class);
    });

    it('sees the sync the HasNotificationReminders trait runs after a save', function (): void {
        $fake = Notifications::fake();

        $contract = contract();

        $fake->assertRemindersSynced($contract);
        expect(fn () => $fake->assertRemindersSynced(contract(expiresAt: null)))->not->toThrow(Throwable::class);
    });

    it('fails assertRemindersSynced when nothing synced', function (): void {
        $contract = contract();

        Notifications::fake()->assertRemindersSynced($contract);
    })->throws(ExpectationFailedException::class, 'synced');

    it('records sweeps', function (): void {
        $fake = Notifications::fake();

        expect(fn () => $fake->assertSwept())->toThrow(ExpectationFailedException::class, 'Expected a sweep');

        Notifications::sweep();
        Notifications::prune();
        Notifications::digests()->sendDue();
        Notifications::deliveries()->dispatchDue();
        Notifications::reminders()->fireDue();
        Notifications::reminders()->backfill('contract.expiring');
        Notifications::reminders()->acknowledge(contract());
        Notifications::preferences(user())->digest(DigestFrequency::Daily)->quietHours('22:00', '07:00')->timezone('UTC')->reset();

        $fake->assertSwept();
        expect($fake->recorded())->toHaveCount(12);
    });

    it('records and runs with passthrough()', function (): void {
        $fake = Notifications::fake()->passthrough();
        $user = user();

        Notifications::send('security.alert', null, $user);

        $fake->assertSent('security.alert', to: $user);
        expect(InboxItem::query()->count())->toBe(1);
    });

    it('lets reads pass through to the real store', function (): void {
        $user = user();
        Notifications::send('security.alert', null, $user);

        Notifications::fake();

        expect(Notifications::inbox($user)->unreadCount())->toBe(1)
            ->and(Notifications::types()->has('comment.posted'))->toBeTrue()
            ->and(Notifications::preferences($user)->matrix())->not->toBeEmpty();
    });

    it('still registers types under the fake', function (): void {
        Notifications::fake();

        Notifications::define('fake.defined')->title(fn (): string => 'x')->register();

        expect(Notifications::types()->has('fake.defined'))->toBeTrue();
    });
});
