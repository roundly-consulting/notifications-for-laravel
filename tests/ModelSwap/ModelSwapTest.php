<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Tests\Fixtures\Swapped\CustomDigestRun;
use RoundlyConsulting\Notifications\Tests\Fixtures\Swapped\CustomInboxItem;
use RoundlyConsulting\Notifications\Tests\Fixtures\Swapped\CustomNotificationDelivery;
use RoundlyConsulting\Notifications\Tests\Fixtures\Swapped\CustomNotificationReceipt;
use RoundlyConsulting\Notifications\Tests\Fixtures\Swapped\CustomNotificationReminder;

it('writes inbox items as the host class', function (): void {
    $approver = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');

    expect('notifications.models.inbox_item')->toHonourModelSwap(CustomInboxItem::class, function () use ($approver, $request): array {
        Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'PR-1']);

        return Notifications::inbox($approver)->get()->all();
    });
});

it('writes deliveries as the host class', function (): void {
    $user = user();

    expect('notifications.models.delivery')->toHonourModelSwap(CustomNotificationDelivery::class, function () use ($user): array {
        Notifications::send('security.alert', null, $user);

        return CustomNotificationDelivery::query()->get()->all();
    });
});

it('writes receipts as the host class', function (): void {
    $user = user();

    expect('notifications.models.receipt')->toHonourModelSwap(CustomNotificationReceipt::class, function () use ($user): array {
        Notifications::type('security.alert')->to($user)->once('login:1')->send();

        return CustomNotificationReceipt::query()->get()->all();
    }, expectsCreation: false);
});

it('writes reminders as the host class', function (): void {
    expect('notifications.models.reminder')->toHonourModelSwap(CustomNotificationReminder::class, function (): array {
        contract(expiresAt: CarbonImmutable::now('UTC')->addDays(100));

        return CustomNotificationReminder::query()->get()->all();
    });
});

it('writes digest runs as the host class', function (): void {
    at('2026-11-09 06:00:00');
    $reader = user();
    $post = post();
    Notifications::preferences($reader)->digest(DigestFrequency::Daily);
    Notifications::send('comment.posted', $post, $reader, ['title' => 'Hello']);

    expect('notifications.models.digest_run')->toHonourModelSwap(CustomDigestRun::class, function (): array {
        at('2026-11-09 08:01:00');
        Notifications::sweep();

        return CustomDigestRun::query()->get()->all();
    });
});
