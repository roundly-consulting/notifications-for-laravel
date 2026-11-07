<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        // An idempotency ledger: no soft deletes (pruned after notifications.idempotency.ttl).
        Schema::create(NotificationsConfig::table('receipts'), function (Blueprint $blueprint): void {
            match (NotificationsConfig::primaryKeyType()) {
                KeyType::BigInt => $blueprint->id(),
                KeyType::Uuid => $blueprint->uuid('id')->primary(),
                KeyType::Ulid => $blueprint->ulid('id')->primary(),
            };

            $blueprint->string('key', 64);
            $blueprint->morphKey('recipient', NotificationsConfig::keyType(), false);
            $blueprint->dateTime('created_at');

            $blueprint->unique(['key', 'recipient_type', 'recipient_id'], 'nrec_unique');
            $blueprint->index('created_at', 'nrec_created_idx');
        });
    }
};
