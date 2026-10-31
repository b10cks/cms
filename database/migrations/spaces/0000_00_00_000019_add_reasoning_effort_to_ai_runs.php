<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reasoning level a run was started with; null means the config's.
 * Applied via spaces:repair-databases on hosted deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ai_runs', 'reasoning_effort')) {
            return;
        }

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->string('reasoning_effort', 10)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn('reasoning_effort');
        });
    }
};
