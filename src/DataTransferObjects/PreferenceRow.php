<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

/**
 * One row of a settings screen: a type and the user's channels for it.
 */
final readonly class PreferenceRow
{
    /**
     * @param  list<ChannelPreference>  $channels
     */
    public function __construct(
        public TypeDefinition $type,
        public array $channels,
    ) {}
}
