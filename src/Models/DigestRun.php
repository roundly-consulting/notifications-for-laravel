<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Notifications\Casts\UtcDateTime;
use RoundlyConsulting\Notifications\Concerns\HasConfigurableKey;
use RoundlyConsulting\Notifications\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Notifications\Database\Factories\DigestRunFactory;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\DigestRunStatus;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/**
 * One digest per recipient, frequency and period — the idempotency ledger that makes a second
 * sweep or a retried job send nothing.
 *
 * @property int|string $id
 * @property string $recipient_type
 * @property int|string $recipient_id
 * @property DigestFrequency $frequency
 * @property string $period
 * @property DigestRunStatus $status
 * @property int $items_count
 * @property int $attempts
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Model|null $recipient
 */
class DigestRun extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<DigestRunFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    public function getTable(): string
    {
        return NotificationsConfig::table('digest_runs');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationModels::delivery(), 'digest_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => DigestFrequency::class,
            'status' => DigestRunStatus::class,
            'items_count' => 'integer',
            'attempts' => 'integer',
            'claimed_at' => UtcDateTime::class,
            'sent_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): DigestRunFactory
    {
        return DigestRunFactory::new();
    }
}
