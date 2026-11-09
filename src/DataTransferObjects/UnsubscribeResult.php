<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\UnsubscribeOutcome;

/**
 * The answer of `Notifications::unsubscribe()->redeem($token)`.
 */
final readonly class UnsubscribeResult
{
    public function __construct(
        public UnsubscribeOutcome $outcome,
        public string $type,
        public ?Model $recipient,
    ) {}
}
