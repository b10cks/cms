<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schedules stay in the management database so `ai:run-schedules` finds the
 * due ones without visiting every space. Their runs live in the space database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            // Runs act as their creator, rechecked on every run.
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('skill');
            // {type, filters}: resolved with EntryScope on every run.
            $table->json('scope');
            $table->string('cron', 100);
            $table->decimal('max_usd', 10, 4);
            $table->boolean('enabled')->default(true);
            $table->string('paused_reason', 40)->nullable();
            $table->timestamp('last_run_at')->nullable();
            // An ai_runs ID in the space database.
            $table->ulid('last_run_id')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'space_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_schedules');
    }
};
