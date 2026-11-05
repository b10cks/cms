<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable execution state of AI runs: who is advancing a run and until when,
 * how often a worker died on it, and how much of its cost its operation has
 * counted. Applied via spaces:repair-databases on hosted deploys, which must
 * run before the code that reads these columns takes traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->string('lease_owner', 26)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedTinyInteger('interruptions')->default(0);
            $table->decimal('counted_cost_usd', 12, 6)->default(0);
            // Set once the provider confirmed cost_usd; until then it is an estimate.
            $table->timestamp('cost_confirmed_at')->nullable();
            $table->index(['state', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropIndex(['state', 'updated_at']);
            $table->dropColumn(['lease_owner', 'lease_expires_at', 'interruptions', 'counted_cost_usd', 'cost_confirmed_at']);
        });
    }
};
