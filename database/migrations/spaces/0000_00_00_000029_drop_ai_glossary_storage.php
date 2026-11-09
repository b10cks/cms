<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AI glossary is gone: its suggestions, the findings on changeset items and
 * the space's suggestions thread, whose kind no longer exists. Each step checks
 * for itself, so a run that stopped halfway picks up where it was. Applied via
 * spaces:repair-databases on hosted deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ai_glossary_suggestions');

        $suggestionThreads = DB::table('ai_conversations')->where('kind', 'suggestions')->select('id');
        DB::table('ai_messages')->whereIn('conversation_id', $suggestionThreads)->delete();
        DB::table('ai_conversations')->where('kind', 'suggestions')->delete();

        if (Schema::hasColumn('ai_changeset_items', 'open_findings')) {
            // SQLite cannot drop a column an index still names.
            Schema::table('ai_changeset_items', function (Blueprint $table) {
                $table->dropIndex(['changeset_id', 'open_findings']);
                $table->dropIndex(['open_findings', 'target_id']);
            });
            Schema::table('ai_changeset_items', fn (Blueprint $table) => $table->dropColumn('open_findings'));
        }

        if (Schema::hasColumn('ai_changeset_items', 'glossary_findings')) {
            Schema::table('ai_changeset_items', fn (Blueprint $table) => $table->dropColumn('glossary_findings'));
        }
    }

    /** The columns and table come back empty; the suggestions thread does not. */
    public function down(): void
    {
        Schema::table('ai_changeset_items', function (Blueprint $table) {
            $table->longText('glossary_findings')->nullable()->after('status');
            $table->unsignedSmallInteger('open_findings')->default(0)->after('glossary_findings');
            $table->index(['changeset_id', 'open_findings']);
            $table->index(['open_findings', 'target_id']);
        });

        Schema::create('ai_glossary_suggestions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('space_id');
            $table->foreignUlid('run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->ulid('actor_id')->nullable();
            $table->string('source_locale', 20);
            $table->string('term');
            $table->json('translations')->nullable();
            $table->json('forbidden')->nullable();
            $table->text('note')->nullable();
            $table->json('evidence')->nullable();
            $table->string('status', 20)->default('pending');
            $table->ulid('glossary_id')->nullable();
            $table->ulid('decided_by_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['space_id', 'status']);
        });
    }
};
