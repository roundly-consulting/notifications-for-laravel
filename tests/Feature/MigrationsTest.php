<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationsConfigurationException;
use RoundlyConsulting\Notifications\Models\DigestRun;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Models\NotificationReceipt;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\NotificationsServiceProvider;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;

it('publishes its migrations instead of auto-loading them', function (): void {
    expect(NotificationsServiceProvider::class)
        ->toNotAutoLoadMigrations()
        ->toPublishMigrationsTimestamped('notifications-migrations', 5);
});

/**
 * Four FK edges: reminders.parent_id (self), inbox_items.reminder_id, deliveries.inbox_item_id,
 * deliveries.digest_run_id. Table names are configurable, so each expression maps to its default.
 */
it('runs its migrations in an order every foreign key can follow', function (): void {
    expect(__DIR__.'/../../database/migrations')->toHaveRunnableMigrationOrder(foreignKeys: 4, tableResolvers: [
        "NotificationsConfig::table('reminders')" => 'notification_reminders',
        "NotificationsConfig::table('inbox_items')" => 'notification_inbox_items',
        "NotificationsConfig::table('digest_runs')" => 'notification_digest_runs',
        "NotificationsConfig::table('deliveries')" => 'notification_deliveries',
        "NotificationsConfig::table('receipts')" => 'notification_receipts',
    ]);
});

it('applies on postgres and refuses a broken order there', function (): void {
    expect(__DIR__.'/../../database/migrations')
        ->toApplyOnConnection('pgsql', 5)
        ->toRejectBrokenOrderOnConnection(static fn (array $files): array => array_reverse($files), 'pgsql');
})->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('pgsql'), 'no PostgreSQL connection');

it('applies on mysql and refuses a broken order there', function (): void {
    expect(__DIR__.'/../../database/migrations')
        ->toApplyOnConnection('mysql', 5)
        ->toRejectBrokenOrderOnConnection(static fn (array $files): array => array_reverse($files), 'mysql');
})->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('mysql'), 'no MySQL connection');

it('refuses a table name too long for MySQL index names', function (string $table, string $value): void {
    config()->set('notifications.tables.'.$table, $value);

    NotificationsConfig::table($table);
})->with([
    'inbox items' => ['inbox_items', str_repeat('x', 31)],
    'reminders' => ['reminders', str_repeat('x', 25)],
    'not a name' => ['deliveries', 'Notification Deliveries'],
])->throws(InvalidNotificationsConfigurationException::class, 'must be a table name');

it('accepts table names right at the bound', function (): void {
    config()->set('notifications.tables.inbox_items', str_repeat('x', 30));
    config()->set('notifications.tables.reminders', str_repeat('y', 24));

    expect(NotificationsConfig::table('inbox_items'))->toBe(str_repeat('x', 30))
        ->and(NotificationsConfig::table('reminders'))->toBe(str_repeat('y', 24));
});

it('refuses an unknown table handle', function (): void {
    NotificationsConfig::table('nope');
})->throws(InvalidNotificationsConfigurationException::class);

it('declares every model swappable through its config key', function (): void {
    expect(InboxItem::class)->toBeSwappableVia('notifications.models.inbox_item')
        ->and(NotificationDelivery::class)->toBeSwappableVia('notifications.models.delivery')
        ->and(NotificationReminder::class)->toBeSwappableVia('notifications.models.reminder')
        ->and(DigestRun::class)->toBeSwappableVia('notifications.models.digest_run')
        ->and(NotificationReceipt::class)->toBeSwappableVia('notifications.models.receipt');
});

it('keeps receipts without soft deletes: an idempotency ledger', function (): void {
    expect(in_array(SoftDeletes::class, class_uses_recursive(NotificationReceipt::class), true))->toBeFalse()
        ->and(in_array(SoftDeletes::class, class_uses_recursive(InboxItem::class), true))->toBeTrue();
});
