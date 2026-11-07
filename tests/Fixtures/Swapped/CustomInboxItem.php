<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\Swapped;

use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass named in `notifications.models.inbox_item`.
 */
class CustomInboxItem extends InboxItem
{
    use CountsCreations;
}
