<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Host models keyed by ULID (members and the publications they acknowledge).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ulid_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });

        Schema::create('ulid_publications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
        });
    }
};
