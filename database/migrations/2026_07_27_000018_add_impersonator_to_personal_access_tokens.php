<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records who started an impersonation session on the token itself.
 *
 * It used to be encoded in the token's name ("impersonation:{userId}"), but
 * users choose the name of every personal access token they create, so that
 * made the identity of the "real" user attacker-supplied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->ulid('impersonator_id')->nullable()->after('abilities');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('impersonator_id');
        });
    }
};
