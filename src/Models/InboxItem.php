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
use RoundlyConsulting\Notifications\Database\Factories\InboxItemFactory;
use RoundlyConsulting\Notifications\DataTransferObjects\ContextReference;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/**
 * One inbox entry: one recipient, type, subject and context per collapse window. Later events in
 * the window raise `count` and update the latest actor and data instead of adding rows.
 * `open_key` is set while the item may still absorb events.
 *
 * @property int|string $id
 * @property string $recipient_type
 * @property int|string $recipient_id
 * @property string $type
 * @property string $category
 * @property Priority $priority
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property string|null $context_type
 * @property int|string|null $context_id
 * @property string|null $context_key
 * @property string|null $context_label
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property int $count
 * @property array<string, mixed> $data
 * @property string $title
 * @property string|null $url
 * @property string|null $open_key
 * @property CarbonImmutable|null $collapse_until
 * @property int|string|null $reminder_id
 * @property CarbonImmutable $first_event_at
 * @property CarbonImmutable $last_event_at
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Model|null $recipient
 * @property-read Model|null $subject
 * @property-read Model|null $actor
 * @property-read Model|null $context
 * @property-read NotificationReminder|null $reminder
 */
class InboxItem extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<InboxItemFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    public function getTable(): string
    {
        return NotificationsConfig::table('inbox_items');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function contextReference(): ?ContextReference
    {
        if ($this->context_key === null) {
            return null;
        }

        return new ContextReference($this->context_key, $this->context_label ?? $this->context_key, $this->context_type, $this->context_id);
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
     * @return MorphTo<Model, $this>
     */
    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<NotificationReminder, $this>
     */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(NotificationModels::reminder(), 'reminder_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'count' => 'integer',
            'data' => 'array',
            'collapse_until' => UtcDateTime::class,
            'first_event_at' => UtcDateTime::class,
            'last_event_at' => UtcDateTime::class,
            'read_at' => UtcDateTime::class,
            'archived_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): InboxItemFactory
    {
        return InboxItemFactory::new();
    }
}
