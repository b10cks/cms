<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('asset_collections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable();

            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->string('icon', 50)->nullable();
            $table->char('color', 7)->nullable()->charset('ascii');

            // 'manual' collections hold explicit items, 'smart' collections
            // are evaluated from the stored filter rules at read time.
            $table->string('type', 10)->charset('ascii')->default('manual');
            $table->json('rules')->nullable();

            $table->foreignUlid('cover_asset_id')->nullable();
            $table->json('settings')->nullable();

            $table->foreignUlid('created_by_id')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('asset_collection_items', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('collection_id');
            $table->foreignUlid('asset_id');

            $table->unsignedInteger('position')->default(0);

            $table->foreignUlid('added_by_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['collection_id', 'asset_id']);
            $table->index(['collection_id', 'position']);
            $table->index('asset_id');
        });

        Schema::create('asset_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name', 150)->nullable();

            $table->string('source_type', 20)->charset('ascii');
            $table->foreignUlid('collection_id')->nullable();
            $table->foreignUlid('folder_id')->nullable();
            $table->json('asset_ids')->nullable();

            $table->string('state', 20)->charset('ascii')->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('error')->nullable();

            $table->string('s3_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('checksum', 64)->charset('ascii')->nullable();
            $table->unsignedInteger('asset_count')->default(0);

            $table->boolean('is_stale')->default(false);

            $table->foreignUlid('created_by_id')->nullable()->comment('Management-database users id');
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index('state');
            $table->index('expires_at');
        });

        Schema::create('asset_shares', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('token', 64)->charset('ascii')->unique();

            $table->string('name', 150);
            $table->text('description')->nullable();

            $table->string('source_type', 20)->charset('ascii');
            $table->foreignUlid('collection_id')->nullable();
            $table->foreignUlid('folder_id')->nullable();
            $table->json('asset_ids')->nullable();

            $table->foreignUlid('package_id')->nullable()->constrained('asset_packages')->nullOnDelete();

            $table->string('password')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('download_limit')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);
            $table->boolean('allow_individual_downloads')->default(true);

            $table->json('settings')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->comment('Management-database users id');
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
            $table->index('expires_at');
        });

        Schema::create('asset_share_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('share_id')->constrained('asset_shares')->cascadeOnDelete();

            $table->string('event', 20)->charset('ascii');
            $table->foreignUlid('asset_id')->nullable();

            $table->char('ip_hash', 64)->charset('ascii')->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['share_id', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_share_events');
        Schema::dropIfExists('asset_shares');
        Schema::dropIfExists('asset_packages');
        Schema::dropIfExists('asset_collection_items');
        Schema::dropIfExists('asset_collections');
    }
};
