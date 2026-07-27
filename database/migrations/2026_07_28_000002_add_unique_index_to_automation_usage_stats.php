<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AutomationUsageService now maintains automation_usage_stats via atomic
 * incremental upserts, which require a unique key on
 * (automation_id, period_type, period_date) — token_usage_stats already has
 * the equivalent index. De-duplicate first (keep the newest row per bucket,
 * ULIDs are monotonic) in case concurrent updateOrCreate calls ever raced.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('automation_usage_stats')
            || Schema::hasIndex('automation_usage_stats', ['automation_id', 'period_type', 'period_date'])) {
            return;
        }

        $keepIds = DB::table('automation_usage_stats')
            ->selectRaw('max(id) as id')
            ->groupBy(['automation_id', 'period_type', 'period_date'])
            ->pluck('id');

        DB::table('automation_usage_stats')->whereNotIn('id', $keepIds)->delete();

        Schema::table('automation_usage_stats', function (Blueprint $table) {
            $table->unique(['automation_id', 'period_type', 'period_date']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('automation_usage_stats')
            && Schema::hasIndex('automation_usage_stats', ['automation_id', 'period_type', 'period_date'])) {
            Schema::table('automation_usage_stats', function (Blueprint $table) {
                $table->dropUnique(['automation_id', 'period_type', 'period_date']);
            });
        }
    }
};
