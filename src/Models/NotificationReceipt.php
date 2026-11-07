<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Casts\UtcDateTime;
use RoundlyConsulting\Notifications\Concerns\HasConfigurableKey;
use RoundlyConsulting\Notifications\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Notifications\Database\Factories\NotificationReceiptFactory;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/**
 * An idempotency record: this key already reached this recipient, so a replay sends nothing.
 * The one model without soft deletes — a ledger, pruned after `notifications.idempotency.ttl`.
 *
 * @property int|string $id
 * @property string $key
 * @property string $recipient_type
 * @property int|string $recipient_id
 * @property CarbonImmutable $created_at
 */
class NotificationReceipt extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<NotificationReceiptFactory> */
    use HasFactory;

    use StoresUtcTimestamps;

    public const null UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return NotificationsConfig::table('receipts');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): NotificationReceiptFactory
    {
        return NotificationReceiptFactory::new();
    }
}
