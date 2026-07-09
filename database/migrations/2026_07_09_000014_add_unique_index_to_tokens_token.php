<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token::findValidToken() looks up by `token` alone on every delivery request.
 * The only index covering `token` was the composite unique (space_id, token),
 * whose leading column is space_id, so a bare `where('token', ...)` could not use
 * it and full-scanned the table. Add a standalone unique index on `token`.
 *
 * Token values are high-entropy secrets, so a global uniqueness constraint is
 * both safe and desirable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tokens') && ! Schema::hasIndex('tokens', ['token'])) {
            Schema::table('tokens', function (Blueprint $table) {
                $table->unique('token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tokens') && Schema::hasIndex('tokens', ['token'])) {
            Schema::table('tokens', function (Blueprint $table) {
                $table->dropUnique(['token']);
            });
        }
    }
};
