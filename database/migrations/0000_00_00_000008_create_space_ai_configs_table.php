<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('space_ai_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('space_id')
                ->constrained('spaces')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('driver', 50);
            $table->string('model');
            $table->text('system_prompt')->nullable();
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->unsignedInteger('max_tokens')->default(32768);
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['space_id', 'is_default']);
        });

        Schema::create('space_ai_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('space_id')
                ->constrained('spaces')
                ->cascadeOnDelete();

            $table->string('driver', 50)->default('openrouter');
            $table->string('key_hash');
            $table->text('encrypted_key');
            $table->string('name');
            $table->string('external_key_hash')->nullable();
            $table->decimal('limit', 10, 2)->nullable();
            $table->string('limit_reset', 20)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();

            $table->index(['space_id', 'driver']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_ai_keys');
        Schema::dropIfExists('space_ai_configs');
    }
};
