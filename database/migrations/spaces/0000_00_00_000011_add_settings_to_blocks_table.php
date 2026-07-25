<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blocks', function (Blueprint $table) {
            // Per-block configuration that is not part of the field schema.
            // First consumer is `slug_pattern`; the column is deliberately a
            // bag so later per-block settings need no further migration.
            $table->json('settings')->nullable()->after('tags');
        });
    }

    public function down(): void
    {
        Schema::table('blocks', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
