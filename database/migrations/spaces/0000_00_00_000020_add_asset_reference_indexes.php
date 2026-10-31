<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the asset usage lookup (AssetUsageService): "is this version a
 * live pointer" checks on contents (current_version_id) and (published_version_id)
 * run per matched version.
 *
 * This migration used to add a multi-valued index on content_versions.asset_ids.
 * Building it crashed Aurora MySQL 3.12 (8.0.44) with a segfault in InnoDB's
 * parallel index builder, taking the shared cluster down, so it was removed.
 * Spaces migrated before that keep the index; it is harmless there.
 *
 * Applied via spaces:repair-databases, which then rebuilds all reference ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contents')) {
            return;
        }

        foreach (['current_version_id', 'published_version_id'] as $column) {
            if (! Schema::hasIndex('contents', [$column])) {
                Schema::table('contents', fn (Blueprint $table) => $table->index([$column], "contents_{$column}_index"));
            }
        }
    }

    public function down(): void
    {
        foreach (['current_version_id', 'published_version_id'] as $column) {
            if (Schema::hasTable('contents') && Schema::hasIndex('contents', "contents_{$column}_index")) {
                Schema::table('contents', fn (Blueprint $table) => $table->dropIndex("contents_{$column}_index"));
            }
        }
    }
};
