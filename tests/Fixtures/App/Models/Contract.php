<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Notifications\Contracts\HasNotificationOwner;
use RoundlyConsulting\Notifications\Traits\HasNotificationReminders;

/**
 * A contract that expires: its reminders follow `expires_at` and stop when it is terminated or
 * deleted.
 *
 * @property int $id
 * @property int $company_id
 * @property int $owner_id
 * @property string $title
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $terminated_at
 * @property-read Company $company
 * @property-read User $owner
 */
class Contract extends Model implements HasNotificationOwner
{
    use HasNotificationReminders;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function notificationOwner(): Model|iterable|null
    {
        return $this->owner;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'terminated_at' => 'immutable_datetime'];
    }
}
