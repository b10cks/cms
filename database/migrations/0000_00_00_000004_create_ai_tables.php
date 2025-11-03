<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name', 100);
            $table->string('model', 150)->unique();
            $table->json('tags')->nullable();
            $table->decimal('token_multiplier', 5, 3)->default(1.000);
            $table->boolean('is_free')->default(false);
            $table->boolean('is_active')->default(true);

            $table->text('description')->nullable();
            $table->string('provider', 50)->nullable();
            $table->json('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'deleted_at']);
            $table->index(['is_free', 'deleted_at']);
            $table->index(['provider', 'deleted_at']);
        });

        Schema::create('space_ai_usage', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('space_id');

            $table->unsignedBigInteger('max_tokens');
            $table->unsignedBigInteger('used_tokens')->default(0);
            $table->timestamp('valid_to');

            $table->timestamps();

            $table->index(['space_id', 'valid_to']);
            $table->index(['valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_ai_usage');
        Schema::dropIfExists('ai_models');
    }
};
