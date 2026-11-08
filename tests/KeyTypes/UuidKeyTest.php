<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\Exceptions\InboxItemNotFoundException;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Models\NotificationReceipt;
use RoundlyConsulting\Notifications\Models\NotificationReminder;

it('runs the whole pipeline with uuid ids', function (): void {
    Notification::fake();
    $user = user();

    Notifications::type('security.alert')->to($user)->once('k')->send();
    $contract = contract();
    $item = InboxItem::query()->sole();
    Notifications::inbox($user)->markRead($item->id);

    expect(Str::isUuid($item->id))->toBeTrue()
        ->and(Str::isUuid(NotificationDelivery::query()->sole()->id))->toBeTrue()
        ->and(Str::isUuid(NotificationReceipt::query()->sole()->id))->toBeTrue()
        ->and(NotificationReminder::query()->count())->toBe(3)
        ->and($item->fresh()->read_at)->not->toBeNull()
        ->and(fn () => Notifications::inbox($user)->find('not-a-uuid'))->toThrow(InboxItemNotFoundException::class);
});

it('route-binds only a valid key', function (): void {
    Notification::fake();
    Notifications::send('security.alert', null, user());
    $item = InboxItem::query()->sole();

    expect((new InboxItem)->resolveRouteBinding($item->id)?->is($item))->toBeTrue()
        ->and(fn () => (new InboxItem)->resolveRouteBinding('not-a-uuid'))->toThrow(ModelNotFoundException::class);
});
