<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_changesets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignUlid('run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->foreignUlid('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            // Inline changesets are reviewed in place; the inbox lists the rest.
            $table->boolean('inline')->default(false);
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->timestamps();
            $table->index(['space_id', 'status', 'inline']);
        });

        Schema::create('ai_changeset_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('changeset_id')->constrained('ai_changesets')->cascadeOnDelete();
            $table->string('operation', 10)->default('update');
            $table->string('target_type', 20);
            $table->string('target_id', 40)->nullable();
            $table->string('target_label')->nullable();
            $table->string('base_version_id', 40)->nullable();
            // Block IDs, never array indexes: `body.<block id>.text`.
            $table->string('path', 500)->nullable();
            $table->string('field_type', 40)->nullable();
            // Long text, not JSON: MySQL reorders JSON keys and before must
            // compare exactly against the value found at accept time.
            $table->longText('before')->nullable();
            $table->longText('after')->nullable();
            $table->longText('current')->nullable();
            $table->string('status', 20)->default('pending');
            $table->longText('glossary_findings')->nullable();
            $table->foreignUlid('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['changeset_id', 'status']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_changeset_items');
        Schema::dropIfExists('ai_changesets');
    }
};
