<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\Notifications\Actions\Digests\SendDigestAction;

/**
 * Sends one claimed digest run.
 */
final class SendDigestJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int|string $runId,
    ) {}

    public function handle(SendDigestAction $send): void
    {
        $send->execute($this->runId);
    }
}
