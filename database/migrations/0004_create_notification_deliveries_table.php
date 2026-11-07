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

        Schema::create(NotificationsConfig::table('deliveries'), function (Blueprint $blueprint) use ($primary): void {
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
            $blueprint->string('type', 100);
            $blueprint->string('channel', 16)->default('mail');
            $blueprint->morphKey('subject', NotificationsConfig::subjectKeyType(), true);
            $blueprint->ownerKey('inbox_item_id', $primary, true, false);
            $blueprint->ownerKey('digest_run_id', $primary, true, false);
            $blueprint->string('digest_frequency', 16)->nullable();
            $blueprint->string('digest_period', 16)->nullable();
            $blueprint->string('status', 16);
            $blueprint->string('open_key', 64)->nullable()->unique('ndel_open_unique');
            $blueprint->dateTime('window_ends_at')->nullable();
            $blueprint->dateTime('send_after')->nullable();
            $blueprint->unsignedInteger('events_count')->default(1);
            $blueprint->dateTime('first_event_at');
            $blueprint->dateTime('last_event_at');
            $blueprint->morphKey('actor', NotificationsConfig::keyType(), true);
            $blueprint->string('context_key', 191)->nullable();
            $blueprint->string('context_label', 255)->nullable();
            $blueprint->json('data');
            $blueprint->longText('payload')->nullable();
            $blueprint->uuid('notification_id')->nullable();
            $blueprint->string('source', 16)->default('type');
            $blueprint->unsignedInteger('attempts')->default(0);
            $blueprint->dateTime('claimed_at')->nullable();
            $blueprint->dateTime('sent_at')->nullable();
            $blueprint->string('cancel_reason', 32)->nullable();
            $blueprint->datetimes();
            $blueprint->softDeletesDatetime();

            $blueprint->index(['status', 'send_after'], 'ndel_due_idx');
            $blueprint->index(['recipient_type', 'recipient_id', 'status'], 'ndel_recipient_idx');
            $blueprint->index('notification_id', 'ndel_notification_idx');
            $blueprint->index('inbox_item_id', 'ndel_item_idx');
            $blueprint->index('digest_run_id', 'ndel_run_idx');
            $blueprint->foreign('inbox_item_id', 'ndel_item_fk')->references('id')->on(NotificationsConfig::table('inbox_items'))->nullOnDelete();
            $blueprint->foreign('digest_run_id', 'ndel_run_fk')->references('id')->on(NotificationsConfig::table('digest_runs'))->nullOnDelete();
        });
    }
};
