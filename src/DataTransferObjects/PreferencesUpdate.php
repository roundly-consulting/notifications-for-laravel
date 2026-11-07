<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

/**
 * Several preference changes applied together — all of them, or none (what `PATCH /preferences`
 * uses). Only what it names changes: an omitted type, category, digest, quiet hours or timezone
 * is kept.
 *
 * ```php
 * Notifications::preferences($user)->update(new PreferencesUpdate(
 *     channels: ['comment.posted' => ['mail' => false]],
 *     categories: ['marketing' => ['mail' => false]],
 *     digest: new DigestPreferenceData(DigestFrequency::Daily, '08:00'),
 * ));
 * ```
 */
final readonly class PreferencesUpdate
{
    /**
     * @param  array<string, array<string, bool>>  $channels  type key → channel (`inbox`, `mail`) → on or off
     * @param  array<string, array<string, bool>>  $categories  category → channel → `false` mutes it, `true` clears the mute
     * @param  bool  $setsQuietHours  whether `quietHours` applies (null then clears them)
     * @param  bool  $setsTimezone  whether `timezone` applies (null then clears it)
     */
    public function __construct(
        public array $channels = [],
        public array $categories = [],
        public ?DigestPreferenceData $digest = null,
        public bool $setsQuietHours = false,
        public ?QuietHours $quietHours = null,
        public bool $setsTimezone = false,
        public ?string $timezone = null,
    ) {}
}
