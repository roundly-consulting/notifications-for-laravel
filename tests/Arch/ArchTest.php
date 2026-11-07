<?php

declare(strict_types=1);

use RoundlyConsulting\Notifications\Models\DigestRun;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Models\NotificationReceipt;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Testing\Arch\ArchPresets;

ArchPresets::strictTypes('RoundlyConsulting\Notifications');

// The manager is non-final so the fake can extend it; the five models are swappable.
ArchPresets::finalByDefault('RoundlyConsulting\Notifications', [
    NotificationsManager::class,
    InboxItem::class,
    NotificationDelivery::class,
    NotificationReminder::class,
    DigestRun::class,
    NotificationReceipt::class,
]);

ArchPresets::swappableModelsAreNotFinal([
    InboxItem::class => 'notifications.models.inbox_item',
    NotificationDelivery::class => 'notifications.models.delivery',
    NotificationReminder::class => 'notifications.models.reminder',
    DigestRun::class => 'notifications.models.digest_run',
    NotificationReceipt::class => 'notifications.models.receipt',
]);

ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Notifications');

ArchPresets::modelsResolveThroughSeam(__DIR__.'/../../src', 'Support', [
    'notifications.models.inbox_item',
    'notifications.models.delivery',
    'notifications.models.reminder',
    'notifications.models.digest_run',
    'notifications.models.receipt',
]);

ArchPresets::factoriesResolveThroughSeam(__DIR__.'/../../database/factories', __DIR__.'/../../src');

ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../../database/migrations');

// The Dependency Policy as a test: php, illuminate/* and roundly packages only.
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../../composer.json');

ArchPresets::noDebuggingLeftovers();

ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Notifications');
