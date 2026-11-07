<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Notifications\Models\DigestRun;

/**
 * A digest mail went out.
 */
final readonly class DigestSent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public DigestRun $run,
        public int $count,
    ) {}
}
