<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use RoundlyConsulting\Notifications\Enums\ReminderStatus;

/**
 * Compare-and-set claim of a due reminder row: `pending → firing`, with a lease.
 *
 * @internal
 */
final class ReminderClaims
{
    public static function claim(int|string $id): bool
    {
        $now = Clock::now()->format(Clock::FORMAT);

        return NotificationModels::reminder()::query()
            ->whereKey($id)
            ->where('status', ReminderStatus::Pending->value)
            ->where('due_at', '<=', $now)
            ->toBase()->incrementEach(['attempts' => 1], ['status' => ReminderStatus::Firing->value, 'claimed_at' => $now, 'updated_at' => $now]) > 0;
    }
}
