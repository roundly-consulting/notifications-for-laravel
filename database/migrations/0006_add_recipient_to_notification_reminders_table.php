<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/**
 * The recipient a repeating reminder chain (`Reminder::every()`) belongs to — one chain per
 * recipient, so each one ends on that recipient's own acknowledgement. Null on every one-off
 * reminder and send-level escalation. Additive: existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $keyType = NotificationsConfig::keyType();

        Schema::table(NotificationsConfig::table('reminders'), function (Blueprint $blueprint) use ($keyType): void {
            $blueprint->morphKey('recipient', $keyType, true);
        });
    }
};
