<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Addresses\AddressesServiceProvider;
use RoundlyConsulting\Connections\ConnectionsServiceProvider;
use RoundlyConsulting\Contacts\ContactsServiceProvider;
use RoundlyConsulting\Notifications\Tests\Fixtures\Contacts\ContactUser;
use RoundlyConsulting\Notifications\Tests\TestCase;

/**
 * contacts-for-laravel installed (require-dev): mail routed to the user's email contact.
 */
abstract class ContactsTestCase extends TestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [
            AddressesServiceProvider::class,
            ConnectionsServiceProvider::class,
            ContactsServiceProvider::class,
            ...parent::packageProviders(),
        ];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [
            AddressesServiceProvider::class,
            ConnectionsServiceProvider::class,
            ContactsServiceProvider::class,
            ...parent::migrationSources(),
        ];
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return ['auth.providers.users.model' => ContactUser::class] + parent::configBeforeBoot();
    }
}
