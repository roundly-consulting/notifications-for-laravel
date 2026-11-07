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

        Schema::create(NotificationsConfig::table('inbox_items'), function (Blueprint $blueprint) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $blueprint->id(),
                KeyType::Uuid => $blueprint->uuid('id')->primary(),
                KeyType::Ulid => $blueprint->ulid('id')->primary(),
            };

            $blueprint->morphKey('recipient', NotificationsConfig::keyType(), false);
            $blueprint->string('type', 100);
            $blueprint->string('category', 100);
            $blueprint->string('priority', 16);
            $blueprint->morphKey('subject', NotificationsConfig::subjectKeyType(), true);
            $blueprint->morphKey('context', NotificationsConfig::contextKeyType(), true);
            $blueprint->string('context_key', 191)->nullable();
            $blueprint->string('context_label', 255)->nullable();
            $blueprint->morphKey('actor', NotificationsConfig::keyType(), true);
            $blueprint->unsignedInteger('count')->default(1);
            $blueprint->json('data');
            $blueprint->string('title', 255);
            $blueprint->string('url', 2048)->nullable();
            $blueprint->string('open_key', 64)->nullable()->unique('ninb_open_unique');
            $blueprint->dateTime('collapse_until')->nullable();
            $blueprint->ownerKey('reminder_id', $primary, true, false);
            $blueprint->dateTime('first_event_at');
            $blueprint->dateTime('last_event_at');
            $blueprint->dateTime('read_at')->nullable();
            $blueprint->dateTime('archived_at')->nullable();
            $blueprint->datetimes();
            $blueprint->softDeletesDatetime();

            $blueprint->index(['recipient_type', 'recipient_id', 'archived_at', 'read_at', 'last_event_at'], 'ninb_list_idx');
            $blueprint->index(['recipient_type', 'recipient_id', 'context_key'], 'ninb_context_idx');
            $blueprint->index('reminder_id', 'ninb_reminder_idx');
            $blueprint->foreign('reminder_id', 'ninb_reminder_fk')->references('id')->on(NotificationsConfig::table('reminders'))->nullOnDelete();
        });
    }
};
