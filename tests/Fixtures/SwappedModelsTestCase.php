<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures;

use RoundlyConsulting\Notifications\Tests\Fixtures\Swapped\CustomDigestRun;
use RoundlyConsulting\Notifications\Tests\Fixtures\Swapped\CustomInboxItem;
use RoundlyConsulting\Notifications\Tests\Fixtures\Swapped\CustomNotificationDelivery;
use RoundlyConsulting\Notifications\Tests\Fixtures\Swapped\CustomNotificationReceipt;
use RoundlyConsulting\Notifications\Tests\Fixtures\Swapped\CustomNotificationReminder;
use RoundlyConsulting\Notifications\Tests\TestCase;

/**
 * Every package model swapped for a host subclass before boot.
 */
abstract class SwappedModelsTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return parent::configBeforeBoot() + [
            'notifications.models.inbox_item' => CustomInboxItem::class,
            'notifications.models.delivery' => CustomNotificationDelivery::class,
            'notifications.models.reminder' => CustomNotificationReminder::class,
            'notifications.models.digest_run' => CustomDigestRun::class,
            'notifications.models.receipt' => CustomNotificationReceipt::class,
        ];
    }
}
