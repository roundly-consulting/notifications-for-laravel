<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use RoundlyConsulting\Notifications\DataTransferObjects\ContextReference;
use RoundlyConsulting\Notifications\NotificationType;

/**
 * What a send's up-front checks produced: the type, the JSON-safe data and the resolved context.
 *
 * @internal
 */
final readonly class ValidatedSend
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public NotificationType $type,
        public array $data,
        public ?ContextReference $context,
    ) {}
}
