<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * A user changed a preference. `scope` is type, category, digest, quiet_hours, timezone or reset.
 */
final readonly class NotificationPreferenceChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Model $user,
        public string $scope,
        public mixed $old,
        public mixed $new,
    ) {}
}
