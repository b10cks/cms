<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Covers the sitemap delivery query, which now paginates in the database:
 * `block_id IN (...) AND language_iso = ? ... ORDER BY full_slug`. The
 * existing (block_id, deleted_at) index leaves the sort unserved, so large
 * spaces filesort every sitemap page.
 *
 * Idempotent and applied to every space database (new databases at creation,
 * existing ones via spaces:repair-databases).
 */
return new class extends Migration
{
    private const array COLUMNS = ['block_id', 'language_iso', 'full_slug'];

    public function up(): void
    {
        if (! Schema::hasTable('contents') || Schema::hasIndex('contents', self::COLUMNS)) {
            return;
        }

        Schema::table('contents', function (Blueprint $table) {
            $table->index(self::COLUMNS);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('contents') || ! Schema::hasIndex('contents', self::COLUMNS)) {
            return;
        }

        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex(self::COLUMNS);
        });
    }
};
