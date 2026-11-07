<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Crypto\CryptoServiceProvider;
use RoundlyConsulting\Notifications\NotificationsServiceProvider;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\AppServiceProvider;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Types\ContractExpiring;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Types\RequestAwaitingApproval;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Types\SecurityAlert;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Types\VendorThing;
use RoundlyConsulting\Options\OptionsServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

/**
 * The test app: the package and its hard dependencies, the fixture app (users, companies,
 * contracts, purchase requests, posts) and its four types — three registered in config, one
 * (`comment.posted`) defined fluently in the fixture AppServiceProvider, after the package booted.
 */
abstract class TestCase extends PackageTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [
            CryptoServiceProvider::class,
            OptionsServiceProvider::class,
            NotificationsServiceProvider::class,
            AppServiceProvider::class,
        ];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [
            OptionsServiceProvider::class,
            NotificationsServiceProvider::class,
            __DIR__.'/Fixtures/migrations',
        ];
    }

    protected function keepsMigratedSchema(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            'app.name' => 'Roundly',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'mail.default' => 'array',
            'queue.default' => 'sync',
            'auth.providers.users.model' => User::class,
            'notifications.types' => [
                SecurityAlert::class,
                RequestAwaitingApproval::class,
                ContractExpiring::class,
                VendorThing::class,
            ],
        ];
    }
}
