<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Notifications\Casts\UtcDateTime;
use RoundlyConsulting\Notifications\Concerns\HasConfigurableKey;
use RoundlyConsulting\Notifications\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Notifications\Database\Factories\NotificationDeliveryFactory;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/**
 * One mail per recipient, type, subject and context per de-dup window: pending, held (quiet
 * hours), digest, sending, sent or a terminal failure. Later events in the window fold into it.
 *
 * @property int|string $id
 * @property string $recipient_type
 * @property int|string $recipient_id
 * @property string $type
 * @property string $channel
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property int|string|null $inbox_item_id
 * @property int|string|null $digest_run_id
 * @property DigestFrequency|null $digest_frequency
 * @property string|null $digest_period
 * @property DeliveryStatus $status
 * @property string|null $open_key
 * @property CarbonImmutable|null $window_ends_at
 * @property CarbonImmutable|null $send_after
 * @property int $events_count
 * @property CarbonImmutable $first_event_at
 * @property CarbonImmutable $last_event_at
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property string|null $context_key
 * @property string|null $context_label
 * @property array<string, mixed> $data
 * @property string|null $payload
 * @property string|null $notification_id
 * @property string $source
 * @property int $attempts
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $sent_at
 * @property CancelReason|null $cancel_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Model|null $recipient
 * @property-read Model|null $subject
 * @property-read Model|null $actor
 * @property-read InboxItem|null $inboxItem
 * @property-read DigestRun|null $digestRun
 */
class NotificationDelivery extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    public const string SOURCE_TYPE = 'type';

    public const string SOURCE_INTEROP = 'interop';

    protected $guarded = [];

    protected $hidden = ['payload'];

    public function getTable(): string
    {
        return NotificationsConfig::table('deliveries');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<InboxItem, $this>
     */
    public function inboxItem(): BelongsTo
    {
        return $this->belongsTo(NotificationModels::inboxItem(), 'inbox_item_id');
    }

    /**
     * @return BelongsTo<DigestRun, $this>
     */
    public function digestRun(): BelongsTo
    {
        return $this->belongsTo(NotificationModels::digestRun(), 'digest_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'digest_frequency' => DigestFrequency::class,
            'cancel_reason' => CancelReason::class,
            'events_count' => 'integer',
            'attempts' => 'integer',
            'data' => 'array',
            'window_ends_at' => UtcDateTime::class,
            'send_after' => UtcDateTime::class,
            'first_event_at' => UtcDateTime::class,
            'last_event_at' => UtcDateTime::class,
            'claimed_at' => UtcDateTime::class,
            'sent_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): NotificationDeliveryFactory
    {
        return NotificationDeliveryFactory::new();
    }
}
