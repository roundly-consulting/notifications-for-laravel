<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use RoundlyConsulting\Notifications\Actions\Sending\SendNotificationAction;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\NotificationsManager;

/*
 * Under Octane every request runs in a sandbox container cloned from the base application. The
 * manager is a singleton a host may resolve at boot (a provider calling `Notifications::…`), so it
 * must never hold on to the container it was built with.
 */

it('resolves actions from the current container', function (): void {
    $user = user();
    $manager = app(NotificationsManager::class);
    Notifications::types(); // the facade caches its root

    $original = Container::getInstance();
    $other = clone $original;
    $other->bind(SendNotificationAction::class, static fn (): never => throw new RuntimeException('current container'));

    Container::setInstance($other);

    try {
        expect(fn () => Notifications::send('security.alert', null, $user))->toThrow(RuntimeException::class, 'current container')
            ->and(fn () => $manager->send('security.alert', null, $user))->toThrow(RuntimeException::class, 'current container')
            ->and($manager->container())->toBe($other);
    } finally {
        Container::setInstance($original);
    }

    Notifications::send('security.alert', null, $user);

    expect(InboxItem::query()->count())->toBe(1);
});

it('lets the fake resolve from the current container too', function (): void {
    $fake = Notifications::fake();

    expect($fake->container())->toBe(Container::getInstance())
        ->and(app(NotificationsManager::class))->toBe($fake);
});
