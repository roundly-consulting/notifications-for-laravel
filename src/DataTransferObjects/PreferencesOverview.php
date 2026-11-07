<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

/**
 * Everything a settings screen shows: the matrix, the digest, quiet hours and the timezone.
 */
final readonly class PreferencesOverview
{
    /**
     * @param  list<PreferenceRow>  $rows
     */
    public function __construct(
        public array $rows,
        public DigestPreferenceData $digest,
        public ?QuietHours $quietHours,
        public string $timezone,
    ) {}
}
