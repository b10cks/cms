<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('asset_folders', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->string('icon', 50)->nullable();
            $table->string('color')->nullable()->charset('ascii');

            $table->foreignUlid('parent_id')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('asset_tags', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name', 100);
            $table->string('icon', 50)->nullable();
            $table->string('color')->nullable()->charset('ascii');

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->ulid('id')->primary();

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

            $table->string('name', 100);
            $table->string('icon', 50)->nullable();
            $table->string('color')->nullable()->charset('ascii');

            $table->foreignUlid('parent_id')->nullable();

            $table->timestamps();
        });

        Schema::create('block_tags', function (Blueprint $table) {
            $table->string('name', 100)->primary();

            $table->string('icon', 50)->nullable();
            $table->string('color')->nullable()->charset('ascii');

            $table->timestamps();
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('slug', 50)->charset('ascii');

            $table->string('name', 100);
            $table->string('icon')->nullable();
            $table->string('color')->nullable();

            $table->string('type', 50)->charset('ascii');

            $table->text('description')->nullable();
            $table->text('preview_template')->nullable();

            $table->json('schema')->nullable();
            $table->json('editor')->nullable();
            $table->json('tags')->nullable();

            $table->foreignUlid('folder_id')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('releases', function (Blueprint $table) {
            $table->ulid('id')->primary();

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

            $table->foreignUlid('block_id');
            $table->foreignUlid('parent_id')->nullable();

            $table->string('name', 100)->nullable();
            $table->string('slug', 50)->charset('ascii');
            $table->string('full_slug')->charset('ascii');

            $table->string('language_iso', 5)->charset('ascii');
            $table->foreignUlid('i18n_parent_id')->nullable();

            $table->jsonb('content')->nullable();
            $table->jsonb('settings')->nullable();

            $table->foreignUlid('current_version_id');
            $table->foreignUlid('published_version_id')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_published_at')->nullable();

            $table->index(['full_slug']);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('content_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('message')->nullable();
            $table->jsonb('content')->nullable();
            $table->jsonb('asset_ids')->nullable();
            $table->jsonb('relation_ids')->nullable();
            $table->jsonb('link_ids')->nullable();

            $table->foreignUlid('content_id');
            $table->foreignUlid('parent_id')->nullable();

            $table->foreignUlid('release_id')->nullable();
            $table->foreignUlid('created_by_id')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['content_id']);
            $table->index(['release_id']);
            $table->index(['content_id', 'created_by_id']);
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('source');
            $table->string('target');

            $table->unsignedSmallInteger('status_code')->default(301);
            $table->unsignedBigInteger('hits')->default(0);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('data_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();

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
            $table->foreignUlid('data_source_id')->constrained()->cascadeOnDelete();

            $table->string('key', 100)->charset('ascii');
            $table->text('value');
            $table->json('dimensions')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['data_source_id', 'key']);
            $table->unique(['data_source_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_entries');
        Schema::dropIfExists('data_sources');
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('content_versions');
        Schema::dropIfExists('contents');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('block_tags');
        Schema::dropIfExists('block_folders');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_folders');
        Schema::dropIfExists('asset_tags');
    }
};
