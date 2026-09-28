<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spaces.icon holds the uploaded file's path, `spaces/icons/{ulid}_{time}.{ext}`,
 * which is 54+ characters. The column was sized for an icon name (50), so
 * strict-mode MySQL rejected every space icon upload with 1406 (issue #40).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->string('icon', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // No shrink back to 50: it would truncate stored icon paths.
    }
};
