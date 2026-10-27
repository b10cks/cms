<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_skills', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope', 10);
            $table->foreignUlid('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->foreignUlid('space_id')->nullable()->constrained('spaces')->cascadeOnDelete();
            $table->string('name', 64);
            $table->boolean('enabled')->default(true);
            $table->longText('draft');
            $table->ulid('published_revision_id')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scope', 'space_id', 'team_id', 'name']);
        });

        Schema::create('ai_skill_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('skill_id')->constrained('ai_skills')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->longText('manifest');
            $table->char('hash', 64);
            $table->foreignUlid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['skill_id', 'revision']);
        });

        Schema::create('ai_knowledge_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope', 10);
            $table->foreignUlid('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->foreignUlid('space_id')->nullable()->constrained('spaces')->cascadeOnDelete();
            $table->string('slug', 120);
            $table->string('title');
            $table->string('type', 20);
            $table->boolean('enabled')->default(true);
            $table->longText('draft');
            $table->ulid('published_revision_id')->nullable();
            $table->string('source_path')->nullable();
            $table->string('source_name')->nullable();
            $table->string('extraction_status', 20)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scope', 'space_id', 'team_id', 'slug']);
        });

        Schema::create('ai_knowledge_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('document_id')->constrained('ai_knowledge_documents')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->longText('markdown');
            $table->char('hash', 64);
            $table->foreignUlid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['document_id', 'revision']);
        });

        Schema::create('ai_knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('revision_id')->constrained('ai_knowledge_revisions')->cascadeOnDelete();
            $table->string('heading_path', 500);
            $table->text('text');
            $table->unsignedInteger('position');
            $table->index(['revision_id', 'position']);

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText(['heading_path', 'text']);
            }
        });

        Schema::create('ai_glossaries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope', 10);
            $table->foreignUlid('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->foreignUlid('space_id')->nullable()->constrained('spaces')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scope', 'space_id', 'team_id']);
        });

        Schema::create('ai_glossary_terms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('glossary_id')->constrained('ai_glossaries')->cascadeOnDelete();
            $table->string('source_locale', 20);
            $table->string('term');
            $table->longText('translations')->nullable();
            $table->boolean('case_sensitive')->default(false);
            $table->boolean('do_not_translate')->default(false);
            $table->longText('forbidden')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['glossary_id', 'source_locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_glossary_terms');
        Schema::dropIfExists('ai_glossaries');
        Schema::dropIfExists('ai_knowledge_chunks');
        Schema::dropIfExists('ai_knowledge_revisions');
        Schema::dropIfExists('ai_knowledge_documents');
        Schema::dropIfExists('ai_skill_revisions');
        Schema::dropIfExists('ai_skills');
    }
};
