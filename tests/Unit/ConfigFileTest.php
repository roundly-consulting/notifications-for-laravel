<?php

declare(strict_types=1);

use Illuminate\Support\Env;

/**
 * The shipped config hands env values to the strict readers RAW: `(bool) env()` would hide a typo.
 */
afterEach(function (): void {
    foreach (['NOTIFICATIONS_QUEUE_ENABLED', 'NOTIFICATIONS_KEY_TYPE', 'NOTIFICATIONS_ROUTES_ENABLED', 'NOTIFICATIONS_INBOX_RECHECK'] as $name) {
        Env::getRepository()->clear($name);
    }
});

/**
 * @return array<string, mixed>
 */
function shippedNotificationsConfig(): array
{
    return require __DIR__.'/../../config/notifications.php';
}

it('ships the defaults when the env is unset', function (): void {
    $config = shippedNotificationsConfig();

    expect($config['queue']['enabled'])->toBeTrue()
        ->and($config['key_type'])->toBe('bigint')
        ->and($config['routes']['enabled'])->toBe('off')
        ->and($config['inbox']['recheck_on_read'])->toBeFalse()
        ->and($config['mail']['strategy'])->toBe('leading')
        ->and($config['digest']['default'])->toBe('off')
        ->and($config['preferences']['inbox_mutable'])->toBeFalse();
});

it('hands unreadable env values to the readers raw', function (string $env, string $path, string $value): void {
    Env::getRepository()->set($env, $value);

    expect(data_get(shippedNotificationsConfig(), $path))->toBe($value);
})->with([
    'queue' => ['NOTIFICATIONS_QUEUE_ENABLED', 'queue.enabled', 'disabled'],
    'key type' => ['NOTIFICATIONS_KEY_TYPE', 'key_type', 'snowflake'],
    'routes' => ['NOTIFICATIONS_ROUTES_ENABLED', 'routes.enabled', 'maybe'],
    'recheck' => ['NOTIFICATIONS_INBOX_RECHECK', 'inbox.recheck_on_read', 'sometimes'],
]);
