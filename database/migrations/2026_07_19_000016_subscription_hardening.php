<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Optional yearly billing option: price for a full year (quotas stay
            // monthly allowances) and the LemonSqueezy variant that sells it.
            $table->decimal('yearly_price', 10, 2)->nullable()->after('price');
            $table->string('ls_variant_id_yearly')->nullable()->charset('ascii')->after('ls_variant_id');

            // Highlighted plan in pickers (frontend already supports `recommended`).
            $table->boolean('is_recommended')->default(false)->after('is_free');

            // Custom/subsidized plans are non-public: hidden from the public plan
            // list and only offered to spaces granted access via plan_space.
            $table->boolean('is_public')->default(true)->after('is_recommended');
        });

        Schema::create('plan_space', function (Blueprint $table) {
            $table->foreignUlid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['plan_id', 'space_id']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // Which billing interval the subscriber picked: month | year. Quota
            // allowances remain monthly regardless of the interval.
            $table->string('billing_interval', 10)->default('month')->charset('ascii')->after('quantity');
        });

        // One row per triggered usage alert, so each space is notified at most
        // once per metric/threshold within an allowance window.
        Schema::create('space_usage_alerts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->string('metric', 20)->charset('ascii');       // storage | traffic | requests | ai
            $table->unsignedSmallInteger('threshold');            // percent: 80, 100
            $table->string('period_key', 10)->charset('ascii');   // allowance window, e.g. 2026-07
            $table->timestamps();

            $table->unique(['space_id', 'metric', 'threshold', 'period_key']);
        });

        // Subscription `quotas` becomes an override-only field (custom deals);
        // plan defaults now resolve at read time. Null out the denormalized
        // copies that are identical to their plan's quotas so future plan
        // changes propagate.
        $plans = DB::table('plans')->pluck('quotas', 'id');

        DB::table('subscriptions')
            ->whereNotNull('quotas')
            ->whereNotNull('plan_id')
            ->orderBy('id')
            ->chunkById(200, function ($subscriptions) use ($plans) {
                foreach ($subscriptions as $subscription) {
                    $planQuotas = $plans[$subscription->plan_id] ?? null;

                    if ($planQuotas !== null
                        && json_decode($subscription->quotas, true) == json_decode($planQuotas, true)) {
                        DB::table('subscriptions')
                            ->where('id', $subscription->id)
                            ->update(['quotas' => null]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_usage_alerts');
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('billing_interval');
        });
        Schema::dropIfExists('plan_space');
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['yearly_price', 'ls_variant_id_yearly', 'is_recommended', 'is_public']);
        });
    }
};
