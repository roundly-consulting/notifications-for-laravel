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

        Schema::create(NotificationsConfig::table('reminders'), function (Blueprint $blueprint) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $blueprint->id(),
                KeyType::Uuid => $blueprint->uuid('id'),
                KeyType::Ulid => $blueprint->ulid('id'),
            };

            // Explicit, so the key exists before any foreign key (incl. a self-reference) points at it.
            if ($primary !== KeyType::BigInt) {
                $blueprint->primary('id');
            }

            $blueprint->string('kind', 16);
            $blueprint->string('type', 100);
            $blueprint->string('rule', 100)->nullable();
            $blueprint->morphKey('subject', NotificationsConfig::subjectKeyType(), false);
            $blueprint->string('anchor', 64)->nullable();
            $blueprint->string('offset', 32)->nullable();
            $blueprint->dateTime('anchor_value')->nullable();
            $blueprint->dateTime('due_at');
            $blueprint->unsignedSmallInteger('level')->default(0);
            $blueprint->ownerKey('parent_id', $primary, true, false);
            $blueprint->json('recipients')->nullable();
            $blueprint->json('data')->nullable();
            $blueprint->string('status', 16);
            $blueprint->string('active_key', 64)->nullable()->unique('nrem_active_unique');
            $blueprint->dateTime('claimed_at')->nullable();
            $blueprint->dateTime('fired_at')->nullable();
            $blueprint->dateTime('acknowledged_at')->nullable();
            $blueprint->morphKey('acknowledger', NotificationsConfig::keyType(), true);
            $blueprint->string('cancel_reason', 32)->nullable();
            $blueprint->unsignedInteger('attempts')->default(0);
            $blueprint->datetimes();
            $blueprint->softDeletesDatetime();

            $blueprint->index(['status', 'due_at'], 'nrem_due_idx');
            $blueprint->index(['subject_type', 'subject_id', 'type'], 'nrem_subject_idx');
            $blueprint->index('parent_id', 'nrem_parent_idx');
            // Escalations are never cascaded away by a prune of their parent.
            $blueprint->foreign('parent_id', 'nrem_parent_fk')->references('id')->on(NotificationsConfig::table('reminders'))->nullOnDelete();
        });
    }
};
