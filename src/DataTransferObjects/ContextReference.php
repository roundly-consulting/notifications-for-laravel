<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

/**
 * Where an inbox item belongs: a stable key (`company:3`, never a class name), a display label
 * snapshot, and — for a model context — its morph type and id.
 */
final readonly class ContextReference
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $type = null,
        public int|string|null $id = null,
    ) {}
}
