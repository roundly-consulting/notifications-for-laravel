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
        $primary = NotificationsConfig::primaryKeyType();

        Schema::create(NotificationsConfig::table('digest_runs'), function (Blueprint $blueprint) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $blueprint->id(),
                KeyType::Uuid => $blueprint->uuid('id'),
                KeyType::Ulid => $blueprint->ulid('id'),
            };

            // Explicit, so the key exists before any foreign key (incl. a self-reference) points at it.
            if ($primary !== KeyType::BigInt) {
                $blueprint->primary('id');
            }

            $blueprint->morphKey('recipient', NotificationsConfig::keyType(), false);
            $blueprint->string('frequency', 16);
            $blueprint->string('period', 16);
            $blueprint->string('status', 16);
            $blueprint->unsignedInteger('items_count')->default(0);
            $blueprint->unsignedInteger('attempts')->default(0);
            $blueprint->dateTime('claimed_at')->nullable();
            $blueprint->dateTime('sent_at')->nullable();
            $blueprint->datetimes();
            $blueprint->softDeletesDatetime();

            // One run per recipient, frequency and period: the digest's idempotency ledger.
            $blueprint->unique(['recipient_type', 'recipient_id', 'frequency', 'period'], 'ndig_period_unique');
        });
    }
};
