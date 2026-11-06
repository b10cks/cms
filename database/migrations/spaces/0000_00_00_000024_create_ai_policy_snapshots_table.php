<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable knowledge and glossary policy snapshots, stored once per content
 * fingerprint. Runs reference one from `setup.policy`, operations from
 * `policy_snapshot_id`. Applied via spaces:repair-databases on hosted deploys,
 * which must run before the code that reads these takes traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_policy_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('space_id');
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('version');
            // Long text, not JSON: MySQL reorders JSON keys and the payload is hashed.
            $table->longText('payload');
            $table->timestamp('created_at')->nullable();
            $table->unique(['space_id', 'fingerprint']);
        });

        Schema::table('ai_operations', function (Blueprint $table) {
            // Set at approval; null for operations approved before snapshots existed.
            $table->ulid('policy_snapshot_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_operations', function (Blueprint $table) {
            $table->dropColumn('policy_snapshot_id');
        });

        Schema::dropIfExists('ai_policy_snapshots');
    }
};
