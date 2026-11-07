<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\Swapped;

use RoundlyConsulting\Notifications\Models\DigestRun;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass named in `notifications.models.digest_run`.
 */
class CustomDigestRun extends DigestRun
{
    use CountsCreations;
}
