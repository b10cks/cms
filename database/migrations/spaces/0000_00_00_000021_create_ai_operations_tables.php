<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI content operations: one approved plan over a frozen scope of up to
 * 10,000 entries, worked through in bounded batch runs. `ai_operation_items`
 * is the ledger that accounts for every entry. Applied via
 * spaces:repair-databases on hosted deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_operations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('space_id');
            $table->ulid('actor_id');
            $table->foreignUlid('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            // Set when a schedule started the operation; plain column, schedules live in the management DB.
            $table->ulid('schedule_id')->nullable();
            $table->string('kind', 20);
            $table->string('skill', 64)->nullable();
            // The skill revision every batch runs with, frozen at creation.
            $table->longText('skill_ref')->nullable();
            $table->string('surface', 64);
            $table->ulid('ai_config_id')->nullable();
            // The outcome the editor asked for.
            $table->longText('prompt');
            // Long text, not JSON: MySQL reorders JSON keys and the plan hash must stay stable.
            $table->longText('scope');
            $table->longText('counts');
            $table->longText('plan')->nullable();
            $table->ulid('plan_run_id')->nullable();
            $table->longText('approved_plan')->nullable();
            $table->string('approved_plan_hash', 64)->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('state', 20)->default('draft');
            $table->string('pause_reason', 40)->nullable();
            $table->boolean('pause_requested')->default(false);
            $table->text('error')->nullable();
            $table->decimal('max_usd', 12, 6)->default(0);
            $table->decimal('spent_usd', 12, 6)->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedInteger('batch_count')->default(0);
            $table->ulid('current_run_id')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['space_id', 'state']);
            $table->index(['schedule_id', 'state']);
        });

        Schema::create('ai_operation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('operation_id')->constrained('ai_operations')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('target_type', 20);
            $table->string('target_id', 40);
            $table->string('label')->nullable();
            // Block slug for content, file family for assets: drives typed counts and review grouping.
            $table->string('group', 120)->nullable();
            $table->string('base_version_id', 40)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('reason', 60)->nullable();
            $table->longText('detail')->nullable();
            $table->unsignedInteger('batch')->nullable();
            $table->ulid('run_id')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['operation_id', 'target_type', 'target_id']);
            $table->index(['operation_id', 'status', 'position']);
            $table->index(['operation_id', 'batch']);
        });

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->ulid('operation_id')->nullable()->index();
        });

        Schema::table('ai_changesets', function (Blueprint $table) {
            // An operation keeps one changeset across all its batch runs.
            $table->ulid('operation_id')->nullable()->unique();
        });

        Schema::table('ai_changeset_items', function (Blueprint $table) {
            // The ledger entry a proposal accounts for; derived creates name their source here.
            $table->unsignedBigInteger('operation_item_id')->nullable()->index();
            // Reasons an item needs individual review: never part of a group accept.
            $table->longText('flags')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_changeset_items', function (Blueprint $table) {
            $table->dropIndex(['operation_item_id']);
            $table->dropColumn(['operation_item_id', 'flags']);
        });
        Schema::table('ai_changesets', function (Blueprint $table) {
            $table->dropUnique(['operation_id']);
            $table->dropColumn('operation_id');
        });
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropIndex(['operation_id']);
            $table->dropColumn('operation_id');
        });
        Schema::dropIfExists('ai_operation_items');
        Schema::dropIfExists('ai_operations');
    }
};
