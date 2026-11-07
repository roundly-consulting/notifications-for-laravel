<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * A recipient got nothing from a send (`reason`: no_access).
 */
final readonly class RecipientSkipped implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $type,
        public Model $recipient,
        public string $reason,
    ) {}
}
