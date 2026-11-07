<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use RoundlyConsulting\Notifications\Models\DigestRun;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Models\NotificationReceipt;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * The model seam: the only place that reads `notifications.models.*`. A host swaps a model by
 * extending the packaged class and naming the subclass in config.
 *
 * @internal
 */
final class NotificationModels
{
    /** @return class-string<InboxItem> */
    public static function inboxItem(): string
    {
        return ModelResolver::for('notifications.models.inbox_item', InboxItem::class);
    }

    /** @return class-string<NotificationDelivery> */
    public static function delivery(): string
    {
        return ModelResolver::for('notifications.models.delivery', NotificationDelivery::class);
    }

    /** @return class-string<NotificationReminder> */
    public static function reminder(): string
    {
        return ModelResolver::for('notifications.models.reminder', NotificationReminder::class);
    }

    /** @return class-string<DigestRun> */
    public static function digestRun(): string
    {
        return ModelResolver::for('notifications.models.digest_run', DigestRun::class);
    }

    /** @return class-string<NotificationReceipt> */
    public static function receipt(): string
    {
        return ModelResolver::for('notifications.models.receipt', NotificationReceipt::class);
    }
}
