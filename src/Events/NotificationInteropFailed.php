<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The interop listener failed inside another package's send; that notification went out untouched. Basenames only.
 */
final readonly class NotificationInteropFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $notification,
        public string $channel,
        public string $exception,
    ) {}
}
