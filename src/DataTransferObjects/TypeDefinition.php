<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\Priority;

/**
 * A registered type as a preferences UI reads it, translated in the current locale.
 */
final readonly class TypeDefinition
{
    /**
     * @param  list<Channel>  $channels
     * @param  list<Channel>  $defaultChannels  the channels on by default
     */
    public function __construct(
        public string $key,
        public string $category,
        public string $label,
        public ?string $description,
        public array $channels,
        public array $defaultChannels,
        public bool $mandatory,
        public Priority $priority,
        public bool $digestible,
    ) {}
}
