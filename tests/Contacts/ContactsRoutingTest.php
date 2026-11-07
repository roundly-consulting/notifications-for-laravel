<?php

declare(strict_types=1);

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\DigestRunStatus;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Mail\DigestMailNotification;
use RoundlyConsulting\Notifications\Mail\TypedMailNotification;
use RoundlyConsulting\Notifications\Models\DigestRun;
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

/**
 * A daily-digest reader with one comment waiting, and the clock at the digest slot.
 */
function digestingContactUser(string $email, bool $verified, ?string $locale = null): ContactUser
{
    at('2026-11-09 06:00:00');
    config()->set('notifications.mail.address_resolver', VerifiedEmailResolver::class);

    $reader = ContactUser::query()->create(['name' => 'Reader', 'email' => 'attribute@example.test', 'locale' => $locale]);
    $contact = $reader->addEmail($email, primary: true);

    if ($verified) {
        $contact->forceFill(['verified_at' => now()])->save();
    }

    Notifications::preferences($reader)->digest(DigestFrequency::Daily);
    Notifications::send('comment.posted', post(), $reader, ['title' => 'P']);
    at('2026-11-09 08:00:00');

    return $reader;
}

it('sends no digest to an address the resolver refuses', function (): void {
    $reader = digestingContactUser('unverified@example.test', verified: false);

    Notifications::sweep();

    Notification::assertNothingSent();
    expect(DigestRun::query()->sole()->status)->toBe(DigestRunStatus::Failed)
        ->and(NotificationDelivery::query()->sole()->cancel_reason)->toBe(CancelReason::NoAddress)
        ->and($reader->routeNotificationFor('mail'))->toBe('unverified@example.test');
});

it('sends the digest to the address the resolver returns, in the recipient locale', function (): void {
    $reader = digestingContactUser('verified@example.test', verified: true, locale: 'sk');

    Notifications::sweep();

    Notification::assertNotSentTo($reader, DigestMailNotification::class);
    Notification::assertSentOnDemand(DigestMailNotification::class, fn (DigestMailNotification $n, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'verified@example.test'
        && $n->locale === 'sk');
    expect(DigestRun::query()->sole()->status)->toBe(DigestRunStatus::Sent);
});
