<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verified email domains per team, and the requests people send to join a
 * team through one. See docs/concepts/team-management.md, decision 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained('teams')->cascadeOnDelete();
            // Lowercase ASCII (punycode for IDNs).
            $table->string('domain', 253)->charset('ascii');
            $table->string('verification_token', 64)->charset('ascii');
            $table->timestamp('verified_at')->nullable();
            $table->string('policy', 20)->charset('ascii')->default('request');
            $table->foreignUlid('default_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->timestamps();

            // Several teams may claim a domain, only one may verify it. The
            // generated column is NULL until verification, and NULLs never
            // collide in a unique index.
            $table->string('verified_domain', 253)->charset('ascii')->nullable()
                ->storedAs('CASE WHEN verified_at IS NULL THEN NULL ELSE domain END');

            $table->unique(['team_id', 'domain']);
            $table->unique('verified_domain');
        });

        Schema::create('team_join_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('team_domain_id')->nullable()->constrained('team_domains')->nullOnDelete();
            $table->string('status', 20)->charset('ascii')->default('pending');
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            // One row per person and team; a new request reuses it.
            $table->unique(['team_id', 'user_id']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_join_requests');
        Schema::dropIfExists('team_domains');
    }
};
