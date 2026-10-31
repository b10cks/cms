<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** off|low|medium|high; null means medium. */
    public function up(): void
    {
        Schema::table('space_ai_configs', function (Blueprint $table) {
            $table->string('reasoning_effort', 10)->nullable()->after('max_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('space_ai_configs', function (Blueprint $table) {
            $table->dropColumn('reasoning_effort');
        });
    }
};
