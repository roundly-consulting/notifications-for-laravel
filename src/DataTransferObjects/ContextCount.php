<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

/**
 * Unread items in one context of an inbox.
 */
final readonly class ContextCount
{
    public function __construct(
        public string $key,
        public string $label,
        public int $unread,
    ) {}
}
