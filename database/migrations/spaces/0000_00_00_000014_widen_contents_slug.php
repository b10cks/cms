<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content slugs were capped at 70 chars, which slug patterns and long
 * translated names hit regularly. 75 keeps the column inside the same
 * index prefix budget as before while giving editors the extra room.
 *
 * Applied to every space database (new ones at creation, existing ones via
 * spaces:repair-databases), so it tolerates a database without the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contents')) {
            return;
        }

        Schema::table('contents', function (Blueprint $table) {
            $table->string('slug', 75)->charset('ascii')->change();
        });
    }

    public function down(): void
    {
        // No shrink back to 70: it would truncate slugs created in between.
    }
};
