<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Recipients\UserRecipients;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\PurchaseRequest;

beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 10:00:00');
});

it('answers 401 without a user (C-33)', function (string $method, string $uri): void {
    $this->json($method, '/notifications/'.$uri)->assertUnauthorized();
})->with([
    ['GET', 'inbox'],
    ['GET', 'inbox/counts'],
    ['POST', 'inbox/1/read'],
    ['POST', 'inbox/read-all'],
    ['GET', 'preferences'],
    ['PATCH', 'preferences'],
    ['GET', 'types'],
]);

it('answers 401 even with the auth middleware emptied (C-33)', function (): void {
    config()->set('notifications.routes.auth_middleware', []);

    $this->getJson('/notifications/inbox')->assertUnauthorized();
});

it('lists, filters and counts the user\'s inbox', function (): void {
    $user = user();
    $request = purchaseRequest();
    $request->company->addMember($user, 'approver');
    Notifications::send('request.awaiting_approval', $request, $user, ['reference' => 'PR-1']);
    Notifications::send('security.alert', null, $user);
    Notifications::send('security.alert', null, user());

    $this->actingAs($user)->getJson('/notifications/inbox')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'security.alert')
        ->assertJsonPath('data.1.context.key', 'company:'.$request->company_id)
        ->assertJsonPath('meta.total', 2);

    $this->actingAs($user)->getJson('/notifications/inbox?filter[context]=company:'.$request->company_id)->assertJsonCount(1, 'data');
    $this->actingAs($user)->getJson('/notifications/inbox?filter[category]=security&filter[status]=unread')->assertJsonCount(1, 'data');
    $this->actingAs($user)->getJson('/notifications/inbox?filter[type]=security.alert&per_page=1')->assertJsonCount(1, 'data');
    $this->actingAs($user)->getJson('/notifications/inbox?filter[status]=archived')->assertJsonCount(0, 'data');
    $this->actingAs($user)->getJson('/notifications/inbox?filter[status]=read')->assertJsonCount(0, 'data');
    $this->actingAs($user)->getJson('/notifications/inbox?filter[status]=all')->assertJsonCount(2, 'data');
    $this->actingAs($user)->getJson('/notifications/inbox?filter[status]=nope')->assertUnprocessable();

    $this->actingAs($user)->getJson('/notifications/inbox/counts')
        ->assertOk()
        ->assertJsonPath('data.unread', 2)
        ->assertJsonPath('data.contexts.0.key', 'company:'.$request->company_id);
});

it('marks read, unread, all read, archives and unarchives — only the user\'s own items (C-32)', function (): void {
    $user = user();
    Notifications::send('security.alert', null, [$user, $other = user()]);
    $mine = InboxItem::query()->where('recipient_id', $user->id)->sole();
    $theirs = InboxItem::query()->where('recipient_id', $other->id)->sole();

    $this->actingAs($user)->postJson("/notifications/inbox/{$mine->id}/read")->assertOk()->assertJsonPath('data.id', $mine->id);
    expect($mine->fresh()->read_at)->not->toBeNull();

    $this->actingAs($user)->deleteJson("/notifications/inbox/{$mine->id}/read")->assertOk()->assertJsonPath('data.read_at', null);
    $this->actingAs($user)->postJson('/notifications/inbox/read-all', ['category' => 'security'])->assertOk()->assertJsonPath('marked', 1);
    $this->actingAs($user)->postJson("/notifications/inbox/{$mine->id}/archive")->assertOk();
    expect($mine->fresh()->archived_at)->not->toBeNull();
    $this->actingAs($user)->deleteJson("/notifications/inbox/{$mine->id}/archive")->assertOk()->assertJsonPath('data.archived_at', null);

    $this->actingAs($user)->postJson("/notifications/inbox/{$theirs->id}/read")
        ->assertNotFound()
        ->assertJsonPath('code', 'inbox_item_not_found');
    $this->actingAs($user)->postJson('/notifications/inbox/abc/read')->assertNotFound();
    $this->actingAs($user)->postJson("/notifications/inbox/{$theirs->id}/archive")->assertNotFound();
    $this->actingAs($user)->deleteJson("/notifications/inbox/{$theirs->id}/archive")->assertNotFound();
    $this->actingAs($user)->deleteJson("/notifications/inbox/{$theirs->id}/read")->assertNotFound();
    expect($theirs->fresh()->read_at)->toBeNull();
});

it('deletes only the user\'s own item over the API', function (): void {
    $user = user();
    Notifications::send('security.alert', null, [$user, $other = user()]);
    $mine = InboxItem::query()->where('recipient_id', $user->id)->sole();
    $theirs = InboxItem::query()->where('recipient_id', $other->id)->sole();

    $this->actingAs($user)->deleteJson("/notifications/inbox/{$theirs->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'inbox_item_not_found');
    $this->actingAs($user)->deleteJson('/notifications/inbox/abc')->assertNotFound();
    $this->actingAs($user)->deleteJson("/notifications/inbox/{$mine->id}")->assertNoContent();
    $this->actingAs($user)->deleteJson("/notifications/inbox/{$mine->id}")->assertNotFound();

    expect(InboxItem::query()->whereKey($mine->id)->exists())->toBeFalse()
        ->and(InboxItem::query()->whereKey($theirs->id)->exists())->toBeTrue()
        ->and(route('notifications.inbox.destroy', $mine->id))->toEndWith("/notifications/inbox/{$mine->id}");
});

it('marks all read of one type through POST /inbox/read-all', function (): void {
    $user = user();
    $request = purchaseRequest();
    $request->company->addMember($user, 'approver');
    Notifications::send('request.awaiting_approval', $request, $user, ['reference' => 'PR-1']);
    Notifications::send('security.alert', null, $user);

    $this->actingAs($user)->postJson('/notifications/inbox/read-all', ['type' => 'security.alert'])->assertOk()->assertJsonPath('marked', 1);

    expect(Notifications::inbox($user)->unreadCount())->toBe(1)
        ->and(Notifications::inbox($user)->type('request.awaiting_approval')->unreadCount())->toBe(1);
    $this->actingAs($user)->postJson('/notifications/inbox/read-all', ['type' => str_repeat('x', 101)])->assertUnprocessable();
});

it('acknowledges the reminder an item came from', function (): void {
    $owner = user();
    $manager = user();
    $contract = contract(owner: $owner, expiresAt: CarbonImmutable::parse('2027-02-17 10:00:00', 'UTC'));
    $contract->company->addMember($manager, 'manager');

    at('2026-12-19 10:00:00');
    Notifications::sweep();
    $item = InboxItem::query()->sole();

    $this->actingAs($owner)->postJson("/notifications/inbox/{$item->id}/acknowledge")->assertOk()->assertJsonPath('acknowledged', 1);
    expect(NotificationReminder::query()->where('kind', 'escalation')->sole()->cancel_reason?->value)->toBe('acknowledged');

    Notifications::send('security.alert', null, $owner);
    $plain = InboxItem::query()->where('type', 'security.alert')->sole();
    $this->actingAs($owner)->postJson("/notifications/inbox/{$plain->id}/acknowledge")->assertOk()->assertJsonPath('acknowledged', 0);
    $this->actingAs($manager)->postJson("/notifications/inbox/{$item->id}/acknowledge")->assertNotFound();
});

it('answers 404 on read, unread, archive and unarchive for an item the reader lost access to when recheck_on_read is on', function (string $method, string $path, bool $recheck): void {
    config()->set('notifications.inbox.recheck_on_read', $recheck);
    $approver = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'PR-1']);
    $item = InboxItem::query()->sole();
    $request->company->users()->detach($approver->id);

    $response = $this->actingAs($approver)->json($method, "/notifications/inbox/{$item->id}/{$path}");

    if ($recheck) {
        $response->assertNotFound()->assertJsonPath('code', 'inbox_item_not_found');
        expect($item->fresh()?->read_at)->toBeNull()
            ->and($item->fresh()?->archived_at)->toBeNull();
    } else {
        $response->assertOk();
    }
})->with([
    'read' => ['POST', 'read'],
    'unread' => ['DELETE', 'read'],
    'archive' => ['POST', 'archive'],
    'unarchive' => ['DELETE', 'archive'],
])->with(['recheck on' => [true], 'recheck off' => [false]]);

it('acknowledges an escalation item of its own level type and stops the chain', function (): void {
    Notifications::define('request.overdue')->subject(PurchaseRequest::class)->title(fn (): string => 'Overdue')->register();
    $approver = user();
    $director = user();
    $board = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver')->addMember($director, 'director')->addMember($board, 'board');
    Notifications::type('request.awaiting_approval')->about($request)->to($approver)->with(['reference' => 'PR-1'])
        ->escalate(after: 'P1D', to: $director, type: 'request.overdue')
        ->escalate(after: 'P2D', to: $board)
        ->send();

    at(CarbonImmutable::now('UTC')->addDay()->toDateTimeString());
    Notifications::sweep();
    $item = InboxItem::query()->where('recipient_id', $director->id)->sole();
    expect($item->type)->toBe('request.overdue');

    $this->actingAs($director)->postJson("/notifications/inbox/{$item->id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('acknowledged', 1);

    expect(NotificationReminder::query()->where('status', 'pending')->count())->toBe(0);
});

it('refuses to acknowledge for a user who lost access to the subject', function (): void {
    $approver = user();
    $director = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver')->addMember($director, 'director');

    Notifications::type('request.awaiting_approval')->about($request)->to($approver)->with(['reference' => 'PR-1'])
        ->escalate(after: 'P1D', to: $director)
        ->send();
    $item = InboxItem::query()->where('recipient_id', $approver->id)->sole();

    $request->company->users()->detach($approver->id);

    $this->actingAs($approver)->postJson("/notifications/inbox/{$item->id}/acknowledge")
        ->assertNotFound()
        ->assertJsonPath('code', 'inbox_item_not_found');
    expect(NotificationReminder::query()->sole()->status->value)->toBe('pending');

    $request->company->addMember($approver, 'approver');
    $this->actingAs($approver)->postJson("/notifications/inbox/{$item->id}/acknowledge")->assertOk()->assertJsonPath('acknowledged', 1);
});

it('answers 404 when acknowledging an item of an unregistered type or a deleted subject', function (): void {
    $owner = user();
    $contract = contract(owner: $owner);
    Notifications::send('contract.expiring', $contract, $owner);
    $item = InboxItem::query()->sole();

    $contract->delete();
    $this->actingAs($owner)->postJson("/notifications/inbox/{$item->id}/acknowledge")->assertNotFound();

    InboxItem::query()->whereKey($item->id)->update(['type' => 'gone.type']);
    $this->actingAs($owner)->postJson("/notifications/inbox/{$item->id}/acknowledge")->assertNotFound();
});

it('shows and updates the user\'s preferences', function (): void {
    $user = user();

    $this->actingAs($user)->getJson('/notifications/preferences')
        ->assertOk()
        ->assertJsonPath('data.digest.frequency.value', 'off')
        ->assertJsonPath('data.timezone', 'UTC')
        ->assertJsonPath('data.quiet_hours', null);

    $this->actingAs($user)->patchJson('/notifications/preferences', [
        'channels' => ['comment.posted' => ['mail' => false], 'request.awaiting_approval' => ['mail' => '1']],
        'categories' => ['contracts' => ['mail' => false], 'requests' => ['mail' => true]],
        'digest' => ['frequency' => 'daily', 'time' => '07:00'],
        'quiet_hours' => ['start' => '22:00', 'end' => '07:00'],
        'timezone' => 'Europe/Bratislava',
    ])->assertOk()
        ->assertJsonPath('data.digest.frequency.value', 'daily')
        ->assertJsonPath('data.digest.time', '07:00')
        ->assertJsonPath('data.quiet_hours.start', '22:00')
        ->assertJsonPath('data.timezone', 'Europe/Bratislava');

    expect(Notifications::preferences($user)->muted('comment.posted'))->toBeTrue()
        ->and(Notifications::preferences($user)->muted('contract.expiring'))->toBeTrue()
        ->and(Notifications::preferences($user)->digestPreference()->frequency)->toBe(DigestFrequency::Daily);

    $this->actingAs($user)->patchJson('/notifications/preferences', ['quiet_hours' => null, 'timezone' => null])->assertOk()
        ->assertJsonPath('data.quiet_hours', null)
        ->assertJsonPath('data.timezone', 'UTC');

    $types = $this->actingAs($user)->getJson('/notifications/preferences')->json('data.types');
    $alert = collect($types)->firstWhere('type.key', 'security.alert');

    expect($alert['channels'][1]['locked'])->toBeTrue()
        ->and($alert['channels'][1]['locked_reason'])->toBe('This notification is required and cannot be switched off.')
        ->and(collect($types)->firstWhere('type.key', 'comment.posted')['channels'][0]['locked_reason'])->toBe('Notifications always appear in the app.');
});

it('refuses to mute a mandatory type over the API with a translated 422', function (): void {
    app()->setLocale('sk');

    $response = $this->actingAs(user())->patchJson('/notifications/preferences', ['channels' => ['security.alert' => ['mail' => false]]])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Upozornenie „security.alert“ je povinné a nemožno ho vypnúť.');

    expect($response->json('errors'))->toBe(['channels.security.alert.mail' => ['Upozornenie „security.alert“ je povinné a nemožno ho vypnúť.']]);
});

it('writes nothing when one PATCH /preferences toggle is refused', function (): void {
    $user = user();

    $response = $this->actingAs($user)->patchJson('/notifications/preferences', [
        'channels' => [
            'request.awaiting_approval' => ['mail' => false],
            'security.alert' => ['mail' => false],
        ],
        'categories' => ['comments' => ['mail' => false], 'junk' => ['mail' => false]],
        'digest' => ['frequency' => 'daily'],
        'timezone' => 'Europe/Bratislava',
    ])->assertUnprocessable();

    expect(array_keys($response->json('errors')))->toBe(['channels.security.alert.mail', 'categories.junk'])
        ->and($response->json('errors')['categories.junk'][0])->toBe('The notification category "junk" is not used by any registered type.')
        ->and(Notifications::preferences($user)->enabled('request.awaiting_approval', Channel::Mail))->toBeTrue()
        ->and(Preferences::channelMap($user))->toBe(['types' => [], 'categories' => []])
        ->and(Notifications::preferences($user)->digestPreference()->frequency)->toBe(DigestFrequency::Off)
        ->and(Notifications::preferences($user)->resolvedTimezone())->toBe('UTC');
});

it('rejects unknown channel keys and unknown type keys in PATCH /preferences', function (array $payload, string $field): void {
    $user = user();

    $response = $this->actingAs($user)->patchJson('/notifications/preferences', $payload)->assertUnprocessable();

    expect(array_keys($response->json('errors')))->toBe([$field])
        ->and(Preferences::channelMap($user))->toBe(['types' => [], 'categories' => []]);
})->with([
    'unknown channel' => [['channels' => ['comment.posted' => ['mail' => false, 'sms' => false]]], 'channels.comment.posted'],
    'unknown category channel' => [['categories' => ['comments' => ['push' => false]]], 'categories.comments'],
    'unknown type' => [['channels' => ['comment.posted' => ['mail' => false], 'nope.type' => ['mail' => false]]], 'channels.nope.type'],
]);

it('rejects switching off the locked inbox in PATCH /preferences', function (): void {
    $user = user();

    $response = $this->actingAs($user)->patchJson('/notifications/preferences', [
        'channels' => ['request.awaiting_approval' => ['mail' => false, 'inbox' => false]],
    ])->assertUnprocessable();

    expect(array_keys($response->json('errors')))->toBe(['channels.request.awaiting_approval.inbox'])
        ->and($response->json('errors')['channels.request.awaiting_approval.inbox'][0])->toBe('The "In-app" channel of the notification "request.awaiting_approval" cannot be switched off.')
        ->and(Preferences::channelMap($user))->toBe(['types' => [], 'categories' => []]);
});

it('rejects unknown categories in PATCH /preferences', function (): void {
    $user = user();
    $junk = [];

    foreach (range(1, 200) as $i) {
        $junk["junk_{$i}"] = ['mail' => false];
    }

    $this->actingAs($user)->patchJson('/notifications/preferences', ['categories' => $junk])
        ->assertUnprocessable();

    expect(Preferences::channelMap($user)['categories'])->toBe([]);
});

it('validates the preferences payload', function (array $payload): void {
    $this->actingAs(user())->patchJson('/notifications/preferences', $payload)->assertUnprocessable();
})->with([
    'bad frequency' => [['digest' => ['frequency' => 'hourly']]],
    'bad time' => [['quiet_hours' => ['start' => '25:00', 'end' => '07:00']]],
    'bad timezone' => [['timezone' => 'Mars/Base']],
    'not a bool' => [['channels' => ['comment.posted' => ['mail' => 'maybe']]]],
    'same quiet times' => [['quiet_hours' => ['start' => '07:00', 'end' => '07:00']]],
    'unknown type' => [['channels' => ['nope.type' => ['mail' => false]]]],
]);

it('lists the types for a settings screen', function (): void {
    $this->actingAs(user())->getJson('/notifications/types')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.key', 'security.alert')
        ->assertJsonPath('data.0.mandatory', true)
        ->assertJsonPath('data.0.priority.value', 'urgent')
        ->assertJsonPath('data.0.channels.1.value', 'mail')
        ->assertJsonPath('data.0.category.key', 'security');
});

it('names its routes', function (): void {
    expect(route('notifications.inbox.index'))->toEndWith('/notifications/inbox')
        ->and(route('notifications.types.index'))->toEndWith('/notifications/types')
        ->and(Recipients::users())->toBeInstanceOf(UserRecipients::class);
});
