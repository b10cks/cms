<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Translation lineage: which source text a translated field was written
 * against, so a later source change marks the field stale. Rows come from AI
 * translation proposals and from the translation's version history. Applied
 * via spaces:repair-databases on hosted deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('translation_sources')) {
            return;
        }

        Schema::create('translation_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The translation row; null until a proposed translation entry is created.
            $table->ulid('content_id')->nullable();
            $table->ulid('changeset_item_id')->nullable();
            $table->ulid('source_content_id');
            $table->string('field_path', 500);
            $table->ulid('source_version_id')->nullable();
            $table->char('source_hash', 64);
            $table->text('source_text');
            // The translated text this lineage describes; a different value has its own row.
            $table->char('target_hash', 64);
            // ai | history | review
            $table->string('origin', 20);
            // missing | empty | stale | same_as_source, for AI rows
            $table->string('reason', 20)->nullable();
            $table->text('previous_source_text')->nullable();
            $table->timestamps();

            $table->index(['content_id', 'field_path']);
            $table->index('changeset_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_sources');
    }
};
