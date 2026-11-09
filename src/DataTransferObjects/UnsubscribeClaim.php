<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * What a valid unsubscribe link names: the recipient (null when it is gone) and the type key.
 */
final readonly class UnsubscribeClaim
{
    public function __construct(
        public ?Model $recipient,
        public string $type,
    ) {}
}
