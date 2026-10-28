<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI threads, runs and their proposals live with the space's content. Users,
 * the space, schedules and glossaries stay in the management database, so
 * their IDs are plain columns without foreign keys. Applied via
 * spaces:repair-databases on hosted deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('space_id');
            // The suggestions and schedule threads have no owner.
            $table->ulid('user_id')->nullable();
            $table->string('kind', 20)->default('chat');
            // Cleared when the schedule is deleted; the thread stays.
            $table->ulid('schedule_id')->nullable()->unique();
            $table->string('surface', 64);
            $table->string('title')->nullable();
            $table->boolean('shared')->default(false);
            // Started from an inline surface: listed, never raising attention.
            $table->boolean('quiet')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            // The screen chips the thread started with.
            $table->longText('context')->nullable();
            $table->timestamps();
            $table->index(['space_id', 'user_id', 'updated_at']);
            $table->index(['space_id', 'last_activity_at']);
        });

        Schema::create('ai_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('space_id');
            $table->ulid('actor_id');
            $table->foreignUlid('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable();
            $table->string('surface', 64);
            $table->string('skill', 64)->nullable();
            $table->ulid('ai_config_id')->nullable();
            $table->string('model')->nullable();
            $table->string('state', 20)->default('running');
            $table->string('mode', 20)->default('inline');
            // Frozen skill, style, glossary and knowledge revisions plus the run's
            // budget, tools and provider options. Long text, not JSON: MySQL
            // reorders JSON keys and the transcript must replay exactly.
            $table->longText('setup');
            $table->longText('input')->nullable();
            $table->longText('transcript');
            $table->longText('questionnaire')->nullable();
            $table->longText('result')->nullable();
            $table->text('error')->nullable();
            $table->boolean('partial')->default(false);
            $table->boolean('cancel_requested')->default(false);
            $table->unsignedInteger('steps')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('event_seq')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['space_id', 'actor_id', 'idempotency_key']);
            $table->index(['space_id', 'state']);
        });

        Schema::create('ai_run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('run_id')->constrained('ai_runs')->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('type', 40);
            $table->longText('payload');
            $table->timestamp('created_at')->nullable();
            $table->unique(['run_id', 'seq']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignUlid('run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->string('role', 20);
            $table->longText('content');
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('ai_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('space_id');
            $table->ulid('user_id');
            $table->foreignUlid('message_id')->nullable()->constrained('ai_messages')->cascadeOnDelete();
            $table->string('name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('url', 2048);
            $table->timestamps();
        });

        Schema::create('ai_changesets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('space_id');
            $table->foreignUlid('run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->ulid('actor_id');
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
            // The value the reviewer accepted when they edited the proposal first.
            $table->longText('final')->nullable();
            $table->longText('current')->nullable();
            $table->string('status', 20)->default('pending');
            $table->longText('glossary_findings')->nullable();
            $table->ulid('decided_by_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            // Set once the item was compared with what an editor kept.
            $table->timestamp('learned_at')->nullable();
            $table->timestamps();
            $table->index(['changeset_id', 'status']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('ai_glossary_suggestions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('space_id');
            $table->foreignUlid('run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            // The editor whose correction produced the suggestion.
            $table->ulid('actor_id')->nullable();
            $table->string('source_locale', 20);
            $table->string('term');
            $table->json('translations')->nullable();
            $table->json('forbidden')->nullable();
            $table->text('note')->nullable();
            // AI text, final text and the entry they came from.
            $table->json('evidence')->nullable();
            $table->string('status', 20)->default('pending');
            // The space or team glossary it was accepted into.
            $table->ulid('glossary_id')->nullable();
            $table->ulid('decided_by_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['space_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_glossary_suggestions');
        Schema::dropIfExists('ai_changeset_items');
        Schema::dropIfExists('ai_changesets');
        Schema::dropIfExists('ai_attachments');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_run_events');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('ai_conversations');
    }
};
