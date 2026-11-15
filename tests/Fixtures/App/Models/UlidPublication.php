<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Traits\HasNotificationReminders;

/**
 * A ULID-keyed reminder subject whose repeating reminders follow `published_at`.
 *
 * @property string $id
 * @property string $title
 * @property CarbonImmutable|null $published_at
 */
class UlidPublication extends Model
{
    use HasNotificationReminders;
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime'];
    }
}
