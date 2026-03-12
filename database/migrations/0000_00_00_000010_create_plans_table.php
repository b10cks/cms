<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->json('name');                   // {"en": "Free", "de": "Kostenlos", "default": "Free"}
            $table->json('description')->nullable(); // {"en": "...", "default": "..."}
            $table->json('features')->nullable();    // {"en": ["feat1", ...], "default": [...]}

            $table->decimal('price', 10, 2)->default(0);
            $table->string('period', 20)->default('month')->charset('ascii'); // month, year, forever

            // Quota limits: null means unlimited
            // requests: API requests/month, traffic: bytes/month, storage: bytes total, aiCredit: tokens/month
            $table->json('quotas')->nullable();

            $table->string('ls_product_id')->nullable()->charset('ascii'); // LemonSqueezy product ID
            $table->string('ls_variant_id')->nullable()->charset('ascii'); // LemonSqueezy variant ID
            $table->string('contact_url')->nullable();

            $table->boolean('is_free')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
