<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/**
 * The instant a snoozed repeating chain (`reminders()->snooze()`) counts its slots from: its next
 * reminder is due then, and every later one whole intervals after it. Null on every chain that was
 * never snoozed and on one-off reminders. Additive: existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(NotificationsConfig::table('reminders'), function (Blueprint $blueprint): void {
            $blueprint->dateTime('snoozed_until')->nullable();
        });
    }
};
