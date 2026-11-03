<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata imports that match rows by filename look them up per chunk. Without
 * this index each lookup scans the space's assets.
 *
 * Applied via spaces:repair-databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assets') && ! Schema::hasIndex('assets', ['filename'])) {
            Schema::table('assets', fn (Blueprint $table) => $table->index('filename'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('assets') && Schema::hasIndex('assets', ['filename'])) {
            Schema::table('assets', fn (Blueprint $table) => $table->dropIndex(['filename']));
        }
    }
};
