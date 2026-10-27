<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('surface', 64);
            $table->string('title')->nullable();
            $table->timestamps();
            $table->index(['space_id', 'user_id', 'updated_at']);
        });

        Schema::create('ai_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignUlid('actor_id')->constrained('users')->cascadeOnDelete();
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
            $table->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('message_id')->nullable()->constrained('ai_messages')->cascadeOnDelete();
            $table->string('name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('url', 2048);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_attachments');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_run_events');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('ai_conversations');
    }
};
