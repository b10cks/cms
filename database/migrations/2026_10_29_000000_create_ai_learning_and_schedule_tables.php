<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_changeset_items', function (Blueprint $table) {
            // The value the reviewer accepted when they edited the proposal first.
            $table->longText('final')->nullable()->after('after');
            // Set once the item was compared with what an editor kept.
            $table->timestamp('learned_at')->nullable()->after('decided_at');
        });

        Schema::create('ai_glossary_suggestions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignUlid('run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            // The editor whose correction produced the suggestion.
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_locale', 20);
            $table->string('term');
            $table->json('translations')->nullable();
            $table->json('forbidden')->nullable();
            $table->text('note')->nullable();
            // AI text, final text and the entry they came from.
            $table->json('evidence')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignUlid('glossary_id')->nullable()->constrained('ai_glossaries')->nullOnDelete();
            $table->foreignUlid('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['space_id', 'status']);
        });

        Schema::create('ai_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            // Runs act as their creator, rechecked on every run.
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('skill');
            // {type, filters}: resolved with EntryScope on every run.
            $table->json('scope');
            $table->string('cron', 100);
            $table->decimal('max_usd', 10, 4);
            $table->boolean('enabled')->default(true);
            $table->string('paused_reason', 40)->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignUlid('last_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->timestamps();
            $table->index(['enabled', 'space_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_schedules');
        Schema::dropIfExists('ai_glossary_suggestions');

        Schema::table('ai_changeset_items', function (Blueprint $table) {
            $table->dropColumn(['final', 'learned_at']);
        });
    }
};
