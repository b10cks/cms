<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Translation memory: one row per translated field of a published translation,
 * filled after publish and by `ai:backfill-translation-memory`. Never shared
 * across spaces. Applied via spaces:repair-databases on hosted deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('translation_units')) {
            return;
        }

        Schema::create('translation_units', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('source_hash', 64);
            $table->string('source_locale', 20);
            $table->string('target_locale', 20);
            $table->text('source_text');
            $table->text('target_text');
            // human | ai_accepted
            $table->string('origin', 20)->default('human');
            // The translation entry and the unit's stable ID within it.
            $table->ulid('content_id');
            $table->string('field_path', 500);
            $table->timestamps();

            $table->unique(['content_id', 'field_path']);
            $table->index(['source_hash', 'source_locale', 'target_locale']);

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText('source_text');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_units');
    }
};
