<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily rollups of per-space DB activity for the provider dashboard, filled by
 * `provider:collect-activity`. The dashboard reads only these, never a space DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('space_activity_daily', function (Blueprint $table) {
            $table->foreignUlid('space_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('versions_created')->default(0);
            $table->unsignedInteger('entries_published')->default(0);
            $table->unsignedInteger('ai_runs')->default(0);
            $table->decimal('ai_cost_usd', 12, 6)->default(0);

            $table->primary(['space_id', 'day']);
            $table->index('day');
        });

        // One row per user and day with any edit or publish, in any space, so
        // distinct editors over a window are one COUNT(DISTINCT).
        Schema::create('editor_activity_daily', function (Blueprint $table) {
            $table->ulid('user_id');
            $table->date('day');

            $table->primary(['day', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editor_activity_daily');
        Schema::dropIfExists('space_activity_daily');
    }
};
