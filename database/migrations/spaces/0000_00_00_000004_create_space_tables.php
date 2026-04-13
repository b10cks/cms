<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('asset_folders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable();

            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->string('icon', 50)->nullable();
            $table->char('color', 7)->nullable()->charset('ascii');

            $table->json('settings')->nullable();
            $table->foreignUlid('parent_id')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('asset_tags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable();

            $table->string('name', 100);
            $table->string('icon', 50)->nullable();
            $table->char('color', 7)->nullable()->charset('ascii');

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable();

            $table->string('filename', 100);
            $table->string('extension', 10)->charset('ascii');
            $table->string('mime_type', 100)->charset('ascii');

            $table->string('path')->charset('ascii')->nullable();

            $table->foreignUlid('storage_id');
            $table->foreignUlid('folder_id')->nullable();

            $table->unsignedInteger('size');

            $table->json('metadata')->nullable();
            $table->json('data')->nullable();
            $table->json('tags')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('block_folders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable();

            $table->string('name', 100);
            $table->string('icon', 50)->nullable();
            $table->char('color', 7)->nullable()->charset('ascii');

            $table->foreignUlid('parent_id')->nullable();

            $table->timestamps();
        });

        Schema::create('block_tags', function (Blueprint $table) {
            $table->string('name', 100)->primary();
            $table->string('external_id', 36)->nullable();

            $table->string('icon', 50)->nullable();
            $table->char('color', 7)->nullable()->charset('ascii');

            $table->timestamps();
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable();

            $table->string('slug', 50)->charset('ascii');

            $table->string('name', 100);
            $table->string('icon', 50)->nullable();
            $table->char('color', 7)->nullable()->charset('ascii');

            $table->string('type', 50)->charset('ascii');

            $table->text('description')->nullable();
            $table->text('preview_template')->nullable();

            $table->json('schema')->nullable();
            $table->json('editor')->nullable();
            $table->json('tags')->nullable();

            $table->foreignUlid('folder_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'deleted_at'], 'blocks_type_deleted_at_index');
        });

        Schema::create('block_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name', 100);
            $table->string('icon', 50)->nullable();
            $table->char('color', 7)->nullable()->charset('ascii');
            $table->text('description')->nullable();
            $table->json('content');
            $table->string('preview_file')->nullable();

            $table->foreignUlid('block_id')->constrained('blocks')->cascadeOnDelete();
            $table->foreignUlid('created_by_id')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('block_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('block_id')->constrained('blocks')->cascadeOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('block_versions')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable();

            $table->json('data');
            $table->text('commit_message')->nullable();

            $table->timestamps();

            $table->index(['block_id', 'created_at']);
        });

        Schema::create('releases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable();

            $table->string('name')->nullable();
            $table->text('description')->nullable();

            $table->jsonb('settings')->nullable();

            $table->foreignUlid('owner_id')->nullable();
            $table->timestamp('publish_at')->nullable();

            $table->timestamp('committed_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable();

            $table->foreignUlid('block_id');
            $table->foreignUlid('parent_id')->nullable();

            $table->string('name', 100)->nullable();
            $table->string('slug', 70)->charset('ascii');
            $table->string('full_slug')->charset('ascii');

            $table->string('language_iso', 5)->charset('ascii');
            $table->foreignUlid('i18n_parent_id')->nullable();

            $table->jsonb('content')->nullable();
            $table->jsonb('settings')->nullable();

            $table->foreignUlid('current_version_id');
            $table->foreignUlid('published_version_id')->nullable();

            $table->longText('searchable_content')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_published_at')->nullable();

            $table->index(['full_slug', 'language_iso']);
            $table->index(['i18n_parent_id', 'deleted_at'], 'contents_i18n_parent_id_deleted_at_index');
            $table->index(['parent_id', 'language_iso', 'slug', 'deleted_at'], 'contents_parent_language_slug_deleted_at_index');
            // Only create fulltext index for MySQL/MariaDB
            if (DB::getDriverName() !== 'sqlite') {
                $table->fullText('searchable_content');
            }
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('content_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable();

            $table->string('message')->nullable();
            $table->jsonb('content')->nullable();
            $table->jsonb('asset_ids')->nullable();
            $table->jsonb('relation_ids')->nullable();
            $table->jsonb('link_ids')->nullable();

            $table->foreignUlid('content_id');
            $table->foreignUlid('parent_id')->nullable();

            $table->foreignUlid('release_id')->nullable();
            $table->foreignUlid('created_by_id')->nullable();
            $table->foreignUlid('published_by_id')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['content_id']);
            $table->index(['release_id']);
            $table->index(['content_id', 'created_by_id']);
            $table->index(['scheduled_at', 'published_at']);
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable();

            $table->string('source');
            $table->string('target');

            $table->unsignedSmallInteger('status_code')->default(301);
            $table->unsignedBigInteger('hits')->default(0);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('data_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable();

            $table->string('name', 100);
            $table->string('slug', 50)->charset('ascii');
            $table->text('description')->nullable();
            $table->json('dimensions')->nullable();
            $table->json('settings')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['slug']);
        });

        Schema::create('data_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable();
            $table->foreignUlid('data_source_id')->constrained()->cascadeOnDelete();

            $table->string('key', 100)->charset('ascii');
            $table->text('value');
            $table->json('dimensions')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['data_source_id', 'key']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable();

            $table->foreignUlid('content_id')->constrained('contents')->onDelete('cascade');
            $table->foreignUlid('content_version_id')->nullable()->constrained('content_versions')->onDelete('cascade');
            $table->foreignUlid('parent_id')->nullable()->constrained('comments')->onDelete('cascade');
            $table->foreignUlid('author_id');

            $table->longText('body');
            $table->boolean('is_resolved')->storedAs('CASE WHEN resolved_at IS NULL THEN 0 ELSE 1 END');

            $table->string('item_id', 26)->nullable();
            $table->string('field', 100)->nullable();

            $table->json('position')->nullable();
            $table->json('mentions_ids')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['content_id', 'is_resolved']);
            $table->index(['content_id']);
            $table->index(['parent_id']);
        });

        Schema::create('comment_reactions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('comment_id')->constrained('comments')->onDelete('cascade');
            $table->foreignUlid('author_id');

            $table->string('emoji', 50)->charset('ascii');

            $table->timestamp('created_at');

            $table->unique(['comment_id', 'author_id', 'emoji'], 'reactions_unique');
            $table->index(['comment_id']);
            $table->index(['author_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('referenced_type')->index();
            $table->string('referenced_id')->index();
            $table->string('name');

            $table->ulid('owner_id')->nullable()->index();
            $table->string('owner_type')->index();
            $table->string('owner_name')->nullable();

            $table->string('operation')->index();
            $table->json('meta')->nullable();

            $table->timestamp('created_at')->nullable()->index();

            $table->index(['referenced_type', 'referenced_id']);
            $table->index(['owner_type', 'owner_id']);
            $table->index(['operation', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('comment_reactions');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('data_entries');
        Schema::dropIfExists('data_sources');
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('content_versions');
        Schema::dropIfExists('contents');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('block_versions');
        Schema::dropIfExists('block_templates');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('block_tags');
        Schema::dropIfExists('block_folders');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_folders');
        Schema::dropIfExists('asset_tags');
    }
};
