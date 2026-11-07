<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('locale', 8)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('password')->default('');
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('company_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('user_id');
            $table->string('role')->nullable();
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('owner_id');
            $table->string('title');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('terminated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('purchase_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('author_id');
            $table->string('reference');
            $table->timestamps();
        });

        // Laravel's own database channel table (interop: keep / both).
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('guests', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id');
            $table->string('title');
            $table->boolean('private')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
