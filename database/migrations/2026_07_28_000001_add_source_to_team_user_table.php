<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records how each team membership came to exist, so the SAML email
     * fallback can tell a membership the user agreed to from one the team
     * simply asserted. Existing rows are left null: their provenance is
     * unknown, and unknown must not be treated as proven.
     */
    public function up(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
