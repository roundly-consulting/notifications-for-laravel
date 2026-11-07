<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Notifications\Casts\UtcDateTime;
use RoundlyConsulting\Notifications\Concerns\HasConfigurableKey;
use RoundlyConsulting\Notifications\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Notifications\Database\Factories\NotificationReminderFactory;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/**
 * A reminder due relative to a subject's date (or at an absolute instant), or an escalation of a
 * reminder or a send. `active_key` is unique while the row is pending or firing.
 *
 * @property int|string $id
 * @property ReminderKind $kind
 * @property string $type
 * @property string|null $rule
 * @property string $subject_type
 * @property int|string $subject_id
 * @property string|null $anchor
 * @property string|null $offset
 * @property CarbonImmutable|null $anchor_value
 * @property CarbonImmutable $due_at
 * @property int $level
 * @property int|string|null $parent_id
 * @property array<string, mixed>|null $recipients
 * @property array<string, mixed>|null $data
 * @property ReminderStatus $status
 * @property string|null $active_key
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $fired_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property string|null $acknowledger_type
 * @property int|string|null $acknowledger_id
 * @property CancelReason|null $cancel_reason
 * @property int $attempts
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Model|null $subject
 * @property-read Model|null $acknowledger
 * @property-read NotificationReminder|null $parent
 */
class NotificationReminder extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<NotificationReminderFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    public function getTable(): string
    {
        return NotificationsConfig::table('reminders');
    }

    public function isEscalation(): bool
    {
        return $this->kind === ReminderKind::Escalation;
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
    public function acknowledger(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<NotificationReminder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(NotificationModels::reminder(), 'parent_id');
    }

    /**
     * @return HasMany<NotificationReminder, $this>
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(NotificationModels::reminder(), 'parent_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReminderKind::class,
            'status' => ReminderStatus::class,
            'cancel_reason' => CancelReason::class,
            'level' => 'integer',
            'attempts' => 'integer',
            'recipients' => 'array',
            'data' => 'array',
            'anchor_value' => UtcDateTime::class,
            'due_at' => UtcDateTime::class,
            'claimed_at' => UtcDateTime::class,
            'fired_at' => UtcDateTime::class,
            'acknowledged_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): NotificationReminderFactory
    {
        return NotificationReminderFactory::new();
    }
}
