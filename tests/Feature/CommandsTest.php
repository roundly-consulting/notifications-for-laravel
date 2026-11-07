<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestRunStatus;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Models\DigestRun;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Models\NotificationReceipt;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Company;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications\HostVendorThingHappened;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications\OneTimeCode;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications\PlainVendorNotice;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications\VendorThingHappened;

beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 10:00:00');
});

it('sweeps everything, or only the parts asked for', function (): void {
    $user = user();
    Notifications::preferences($user)->quietHours('09:00', '10:30');
    Notifications::send('request.awaiting_approval', $request = purchaseRequest(), tap($user, fn ($u) => $request->company->addMember($u, 'approver')), ['reference' => 'A']);

    at('2026-11-09 10:30:00');

    $this->artisan('notifications:sweep', ['--only' => 'claims,reminders'])
        ->expectsOutput('Deliveries: 0, digests: 0, reminders: 0, escalations: 0, expired claims: 0.')
        ->assertSuccessful();

    $this->artisan('notifications:sweep')
        ->expectsOutput('Deliveries: 1, digests: 0, reminders: 0, escalations: 0, expired claims: 0.')
        ->assertSuccessful();

    $this->artisan('notifications:sweep', ['--only' => 'everything'])->assertExitCode(2);
});

it('prunes old read and archived items, finished rows and expired receipts', function (): void {
    $user = user();
    InboxItem::factory()->recipient($user)->read()->archived()->create(['last_event_at' => now()->subDays(181)]);
    InboxItem::factory()->recipient($user)->read()->create(['last_event_at' => now()->subDays(181)]);
    NotificationDelivery::factory()->recipient($user)->status(DeliveryStatus::Sent)->create(['updated_at' => now()->subDays(31)]);
    NotificationDelivery::factory()->recipient($user)->status(DeliveryStatus::Pending)->create(['updated_at' => now()->subDays(31)]);
    DigestRun::factory()->recipient($user)->create(['status' => DigestRunStatus::Sent, 'updated_at' => now()->subDays(91)]);
    $parent = NotificationReminder::factory()->create(['status' => ReminderStatus::Sent, 'updated_at' => now()->subDays(91)]);
    NotificationReminder::factory()->create(['status' => ReminderStatus::Pending, 'parent_id' => $parent->id, 'kind' => 'escalation']);
    NotificationReminder::factory()->create(['status' => ReminderStatus::Cancelled, 'updated_at' => now()->subDays(91)]);
    NotificationReceipt::factory()->create(['created_at' => now()->subDays(31)]);
    NotificationReceipt::factory()->create(['key' => str_repeat('b', 64)]);

    $this->artisan('notifications:prune')
        ->expectsOutput('Pruned 5 row(s): inbox items 1, deliveries 1, digest runs 1, reminders 1, receipts 1.')
        ->assertSuccessful();

    expect(InboxItem::query()->count())->toBe(1)
        ->and(NotificationReminder::query()->count())->toBe(2)
        ->and(NotificationReceipt::query()->count())->toBe(1);
});

it('lists the registered types', function (): void {
    $this->artisan('notifications:types')
        ->expectsTable(['Key', 'Category', 'Channels', 'Priority', 'Flags', 'Window', 'Reminder rules'], [
            ['security.alert', 'security', 'inbox, mail', 'urgent', 'mandatory', '-', '0'],
            ['request.awaiting_approval', 'requests', 'inbox, mail', 'normal', '', 'PT5M', '0'],
            ['contract.expiring', 'contracts', 'inbox, mail', 'normal', '', '-', '1'],
            ['vendor.thing', 'vendor', 'inbox, mail', 'normal', '', 'PT15M', '0'],
            ['comment.posted', 'comments', 'inbox, mail', 'low', 'digestible', 'PT10M', '0'],
        ])
        ->assertSuccessful();

    config()->set('notifications.types', []);
    app(TypeRegistry::class)->flush();

    $this->artisan('notifications:types')->expectsOutput('No notification types are registered.')->assertSuccessful();
});

it('backfills reminders for one type or every type with rules', function (): void {
    contract();
    NotificationReminder::query()->forceDelete();

    $this->artisan('notifications:reminders:sync', ['type' => 'contract.expiring'])->expectsOutput('contract.expiring: 1 subject(s) synced.')->assertSuccessful();
    $this->artisan('notifications:reminders:sync')->expectsOutput('contract.expiring: 1 subject(s) synced.')->assertSuccessful();

    expect(NotificationReminder::query()->count())->toBe(3);
});

it('finds no problems in a sound setup', function (): void {
    config()->set('notifications.preferences.lock_store', 'redis-locks');
    config()->set('cache.stores.redis-locks', ['driver' => 'redis']);
    config()->set('queue.default', 'redis');

    $this->artisan('notifications:doctor')
        ->expectsOutput('No problems found.')
        ->assertSuccessful();
});

it('reports every misconfiguration it checks', function (): void {
    config()->set('options.key_type', 'uuid');
    config()->set('notifications.quiet_hours.default', 'nonsense');
    config()->set('auth.providers.users.model', Company::class);
    config()->set('notifications.interop.map', [
        PlainVendorNotice::class => 'missing.type',
        OneTimeCode::class => 'request.awaiting_approval',
        VendorThingHappened::class => 'comment.posted',
    ]);
    Notifications::define('nopolicy.doctor')->subject(Company::class)->title(fn (): string => 'x')->register();
    Notifications::define('vendor.nosubject')->subject(Post::class)->access(fn (): bool => true)->title(fn (): string => 'x')->register();
    Notifications::interop()->map(HostVendorThingHappened::class, 'vendor.nosubject');

    $this->artisan('notifications:doctor')
        ->expectsOutputToContain('differs from options.key_type (uuid)')
        ->expectsOutputToContain('The queue connection is sync')
        ->expectsOutputToContain('uses the array driver')
        ->expectsOutputToContain('notifications.quiet_hours.default')
        ->expectsOutputToContain('Type [nopolicy.doctor]: Company has no policy')
        ->expectsOutputToContain('PlainVendorNotice maps to the unknown type [missing.type]')
        ->expectsOutputToContain('OneTimeCode is time-critical')
        ->expectsOutputToContain('[comment.posted] is digestible, but mapped notifications are never digested')
        ->expectsOutputToContain('[vendor.nosubject] has a subject but no subjectFrom()')
        ->expectsOutputToContain('Company cannot be mailed')
        ->expectsOutputToContain('Company is not Authenticatable')
        ->assertFailed();
});
