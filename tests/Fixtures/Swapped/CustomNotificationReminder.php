<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\Swapped;

use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass named in `notifications.models.reminder`.
 */
class CustomNotificationReminder extends NotificationReminder
{
    use CountsCreations;
}
