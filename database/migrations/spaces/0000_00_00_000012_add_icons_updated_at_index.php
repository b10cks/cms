<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an index on icons.updated_at: the Iconify delivery endpoints compute
 * their Last-Modified header from MAX(updated_at), which without an index is
 * a full scan of the icons table on every request.
 *
 * Idempotent and applied to every space database (new databases at creation,
 * existing ones via spaces:repair-databases).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('icons')
            || ! Schema::hasColumn('icons', 'updated_at')
            || Schema::hasIndex('icons', ['updated_at'])) {
            return;
        }

        Schema::table('icons', function (Blueprint $table) {
            $table->index(['updated_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('icons') || ! Schema::hasIndex('icons', ['updated_at'])) {
            return;
        }

        Schema::table('icons', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
        });
    }
};
