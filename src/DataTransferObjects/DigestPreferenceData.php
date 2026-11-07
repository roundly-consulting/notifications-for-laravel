<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

use RoundlyConsulting\Notifications\Enums\DigestFrequency;

/**
 * A user's digest preference: how often, and at which local time (null = the configured time).
 */
final readonly class DigestPreferenceData
{
    public function __construct(
        public DigestFrequency $frequency,
        public ?string $time = null,
    ) {}
}
