<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignUlid('plan_id')->nullable()->after('space_id')
                ->constrained('plans')->nullOnDelete();

            // LemonSqueezy customer ID (null for free plans)
            $table->string('ls_customer_id')->nullable()->after('lemon_squeezy_id');

            // Cached billing portal URL for quick access
            $table->string('billing_portal_url', 1000)->nullable()->after('ls_customer_id');

            // Quota snapshot at subscription time (may differ from plan quotas if grandfathered)
            $table->json('quotas')->nullable()->after('attributes');

            // Make lemon_squeezy_id nullable to support free plan subscriptions (no LS record)
            $table->string('lemon_squeezy_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn(['plan_id', 'ls_customer_id', 'billing_portal_url', 'quotas']);
            $table->string('lemon_squeezy_id')->nullable(false)->change();
        });
    }
};
