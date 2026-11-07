<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('publishes the config and migrations into the sandbox and prints the next steps', function (): void {
    config()->set('notifications.tables.inbox_items', 'not_yet_inbox');
    config()->set('notifications.tables.deliveries', 'not_yet_deliveries');
    config()->set('notifications.tables.reminders', 'not_yet_reminders');
    config()->set('notifications.tables.digest_runs', 'not_yet_digest_runs');
    config()->set('notifications.tables.receipts', 'not_yet_receipts');

    $this->artisan('notifications:install')
        ->expectsOutputToContain("Schedule::command('notifications:sweep')->everyMinute()->withoutOverlapping(10)->onOneServer();")
        ->assertSuccessful();

    expect(config_path('notifications.php'))->toStartWith($this->sandbox)->toBeFile()
        ->and(File::glob(database_path('migrations/*_create_notification_*_table.php')))->toHaveCount(5);
});

it('refuses to install over existing tables', function (): void {
    $this->artisan('notifications:install')
        ->expectsOutputToContain('already exists')
        ->assertFailed();
});
