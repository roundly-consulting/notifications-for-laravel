<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationReminder;

it('runs the whole pipeline with ulid ids', function (): void {
    Notification::fake();
    $user = user();

    Notifications::send('security.alert', null, $user);
    contract(owner: $user, expiresAt: now()->toImmutable()->addDays(70));
    CarbonImmutable::setTestNow(now()->addDays(11));
    Notifications::sweep();

    expect(Str::isUlid(InboxItem::query()->first()->id))->toBeTrue()
        ->and(NotificationReminder::query()->where('status', 'sent')->count())->toBe(1)
        ->and(Str::isUlid((string) InboxItem::query()->whereNotNull('reminder_id')->sole()->reminder_id))->toBeTrue();
});
