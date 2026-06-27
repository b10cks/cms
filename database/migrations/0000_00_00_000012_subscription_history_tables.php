<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('space_id')
                ->constrained('spaces')
                ->cascadeOnDelete();

            $table->foreignUlid('subscription_id')->nullable()
                ->constrained('subscriptions')
                ->nullOnDelete();

            $table->foreignUlid('plan_id')->nullable()
                ->constrained('plans')
                ->nullOnDelete();

            // Snapshot of the plan as it was during the period (survives rename/delete).
            $table->string('plan_name');
            $table->json('quotas')->nullable();          // effective quotas during the period
            $table->decimal('price', 10, 2)->default(0);
            $table->string('billing_period', 20)->default('month')->charset('ascii'); // month, year, forever
            $table->string('status', 20);                // subscription status snapshot

            // Runtime window. ended_at null = the currently open period.
            $table->timestamp('started_at');
            $table->timestamp('renews_at')->nullable();  // cycle renewal anchor (for rollover detection)
            $table->timestamp('ended_at')->nullable();
            // created | renewed | upgraded | downgraded | cancelled | expired
            $table->string('close_reason', 20)->nullable()->charset('ascii');

            // Usage rollups, filled when the period closes.
            $table->unsignedBigInteger('storage_bytes')->nullable();
            $table->unsignedBigInteger('traffic_bytes')->nullable();
            $table->unsignedBigInteger('requests_count')->nullable();
            $table->decimal('ai_spend_usd', 12, 6)->nullable();

            $table->timestamps();

            $table->index(['space_id', 'started_at']);
            $table->index(['space_id', 'ended_at']);
        });

        Schema::table('space_ai_keys', function (Blueprint $table) {
            // Final OpenRouter spend (USD) captured before the key is revoked, so
            // per-period AI usage survives key rotation. Null until captured.
            $table->decimal('final_usage_usd', 12, 6)->nullable()->after('limit_reset');
            $table->timestamp('usage_captured_at')->nullable()->after('final_usage_usd');
        });
    }

    public function down(): void
    {
        Schema::table('space_ai_keys', function (Blueprint $table) {
            $table->dropColumn(['final_usage_usd', 'usage_captured_at']);
        });
        Schema::dropIfExists('subscription_periods');
    }
};
