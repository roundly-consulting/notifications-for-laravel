<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

/**
 * A type category with its translated label.
 */
final readonly class CategoryDefinition
{
    public function __construct(
        public string $key,
        public string $label,
    ) {}
}
