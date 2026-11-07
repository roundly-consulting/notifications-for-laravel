<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Preferences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\Events\NotificationPreferenceChanged;
use RoundlyConsulting\Notifications\Options\ChannelPreferences;
use RoundlyConsulting\Notifications\Support\Preferences;

/**
 * Forgets every type and category override of a user: back to every type's defaults. Digest,
 * quiet hours and timezone are kept.
 */
final readonly class ResetPreferencesAction
{
    public function execute(Model $user): void
    {
        $old = Preferences::write(ChannelPreferences::class, $user, static fn (mixed $current): array => [null, Preferences::normaliseMap($current)]);

        Event::dispatch(new NotificationPreferenceChanged($user, 'reset', $old, null));
    }
}
