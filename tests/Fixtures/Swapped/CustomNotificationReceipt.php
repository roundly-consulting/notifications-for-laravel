<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\Swapped;

use RoundlyConsulting\Notifications\Models\NotificationReceipt;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass named in `notifications.models.receipt`.
 */
class CustomNotificationReceipt extends NotificationReceipt
{
    use CountsCreations;
}
