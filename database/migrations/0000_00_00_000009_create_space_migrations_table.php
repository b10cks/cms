<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('space_migrations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignUlid('target_space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('state', 50)->default('pending')->charset('ascii');
            $table->unsignedTinyInteger('progress')->default(0);

            $table->json('scope');
            $table->string('conflict_strategy', 50)->default('skip')->charset('ascii');

            $table->json('stats')->nullable();
            $table->json('result')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['source_space_id', 'state']);
            $table->index(['target_space_id', 'state']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_migrations');
    }
};
