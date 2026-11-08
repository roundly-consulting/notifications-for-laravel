<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

it('renders its about section without leaking anything', function (): void {
    expect('notifications')->toLeakNoSecrets(secrets: [], mustRender: ['Types', '5', 'Queue', 'Key type', 'bigint', 'Interop', 'Routes', 'OFF']);
});

it('shows a set queue connection only as SET', function (): void {
    config()->set('notifications.queue.connection', 'redis-secret-host');

    Artisan::call('about', ['--only' => 'notifications']);

    expect(Artisan::output())->toContain('connection SET')->not->toContain('redis-secret-host');
});

it('reads the queue switch the way an env string means it', function (string|bool $value, string $shown): void {
    config()->set('notifications.queue.enabled', $value);

    Artisan::call('about', ['--only' => 'notifications']);

    expect(Artisan::output())->toContain($shown);
})->with([
    ['off', 'OFF (inline)'],
    ['0', 'OFF (inline)'],
    [false, 'OFF (inline)'],
    ['on', 'ON (connection DEFAULT'],
    [true, 'ON (connection DEFAULT'],
]);

it('refuses a switch it cannot read', function (): void {
    config()->set('notifications.queue.enabled', 'disabled');

    expect(fn () => Artisan::call('about', ['--only' => 'notifications']))->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [notifications.queue.enabled] must be a boolean',
    );
});

it('uses the defaults when a key is blank', function (?string $value): void {
    config()->set('notifications.queue.enabled', $value);
    config()->set('notifications.key_type', $value);

    Artisan::call('about', ['--only' => 'notifications']);

    expect(Artisan::output())->toContain('ON (connection')->toContain('bigint');
})->with(['null' => [null], 'empty env' => [''], 'whitespace' => ['  ']]);

it('lists the context key type in about', function (): void {
    expect('notifications')->toLeakNoSecrets(secrets: [], mustRender: ['Context key type', 'bigint']);

    config()->set('notifications.context_key_type', 'ulid');
    Artisan::call('about', ['--only' => 'notifications']);

    expect(Artisan::output())->toMatch('/Context key type\W+ulid/');
});
