<?php

declare(strict_types=1);

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Mail\TypedMailNotification;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\Tests\Fixtures\Contacts\ContactUser;
use RoundlyConsulting\Notifications\Tests\Fixtures\Contacts\VerifiedEmailResolver;

beforeEach(function (): void {
    Notification::fake();
});

function contactUser(): ContactUser
{
    return ContactUser::query()->create(['name' => 'Contact', 'email' => 'attribute@example.test']);
}

it('sends mail to the primary email contact (C-67)', function (): void {
    $user = contactUser();
    $user->addEmail('primary@example.test', primary: true);

    Notifications::send('security.alert', null, $user);

    Notification::assertSentTo($user, TypedMailNotification::class);
    expect($user->routeNotificationFor('mail'))->toBe('primary@example.test')
        ->and(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Sent);
});

it('makes a user without a primary email contact undeliverable (C-67)', function (): void {
    $user = contactUser();

    Notifications::send('security.alert', null, $user);

    Notification::assertNothingSent();
    expect(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Undeliverable);
});

it('sends only to a verified primary email with the recipe resolver', function (): void {
    config()->set('notifications.mail.address_resolver', VerifiedEmailResolver::class);

    $unverified = contactUser();
    $unverified->addEmail('unverified@example.test', primary: true);

    $verified = contactUser();
    $verified->addEmail('verified@example.test', primary: true)->forceFill(['verified_at' => now()])->save();

    Notifications::send('security.alert', null, [$unverified, $verified]);

    Notification::assertSentOnDemand(TypedMailNotification::class, fn ($n, $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'verified@example.test');
    expect(NotificationDelivery::query()->orderBy('id')->pluck('status')->all())->toBe([DeliveryStatus::Undeliverable, DeliveryStatus::Sent])
        ->and((new VerifiedEmailResolver)->address(user(), new NotificationPayload('x', user(), null, null, null, null, [], 1, now()->toImmutable(), now()->toImmutable(), Channel::Mail)))->toBeNull();
});
