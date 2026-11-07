<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\Swapped;

use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass named in `notifications.models.delivery`.
 */
class CustomNotificationDelivery extends NotificationDelivery
{
    use CountsCreations;
}
