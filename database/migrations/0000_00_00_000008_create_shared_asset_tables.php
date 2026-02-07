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
        // Shared Asset Libraries - Team-level asset libraries
        Schema::create('shared_asset_libraries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            
            $table->string('name', 100);
            $table->string('slug', 50)->charset('ascii');
            $table->text('description')->nullable();
            $table->string('icon', 50)->nullable();
            $table->char('color', 7)->nullable()->charset('ascii');
            
            $table->boolean('is_default')->default(false);
            $table->json('settings')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['team_id', 'slug']);
            $table->index(['team_id', 'is_default']);
        });

        // Shared Assets - References to assets shared from spaces
        Schema::create('shared_assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('library_id')->constrained('shared_asset_libraries')->cascadeOnDelete();
            
            // Reference to original asset in space database
            $table->foreignUlid('source_space_id')->constrained('spaces')->cascadeOnDelete();
            $table->ulid('source_asset_id'); // Asset ID in source space's database
            
            // Optional overrides for shared context
            $table->string('shared_name', 100)->nullable();
            $table->text('shared_description')->nullable();
            $table->json('shared_tags')->nullable();
            $table->json('shared_metadata')->nullable();
            
            // Usage tracking
            $table->integer('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Ensure unique sharing per library
            $table->unique(['library_id', 'source_space_id', 'source_asset_id'], 'shared_assets_unique');
            $table->index(['source_space_id', 'source_asset_id']);
            $table->index('library_id');
        });

        // Shared Asset Permissions - Control access to shared assets
        Schema::create('shared_asset_permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            
            // What is being shared (library-level or asset-level)
            $table->foreignUlid('library_id')->nullable()->constrained('shared_asset_libraries')->cascadeOnDelete();
            $table->foreignUlid('shared_asset_id')->nullable()->constrained('shared_assets')->cascadeOnDelete();
            
            // Who can access it (polymorphic: Team, Space, or User)
            $table->nullableUlidMorphs('accessor');
            
            $table->string('permission', 50)->charset('ascii'); // 'view', 'use', 'download'
            $table->boolean('inherited')->default(false); // Inherited from parent team
            
            $table->json('conditions')->nullable(); // Additional access conditions
            
            $table->timestamps();
            
            $table->index(['library_id', 'accessor_type', 'accessor_id']);
            $table->index(['shared_asset_id', 'accessor_type', 'accessor_id']);
            
            // Ensure unique permission per accessor and resource
            $table->unique([
                'library_id', 
                'shared_asset_id', 
                'accessor_type', 
                'accessor_id'
            ], 'shared_permissions_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shared_asset_permissions');
        Schema::dropIfExists('shared_assets');
        Schema::dropIfExists('shared_asset_libraries');
    }
};
