<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The hard unique constraints made re-inviting impossible once any
        // invite row existed for an address (accepted-then-removed, expired,
        // …). Uniqueness of *pending* invites is enforced in
        // StoreInviteRequest/CreateInvite instead. The plain replacement
        // indexes are created first so MySQL keeps an index backing the
        // space_id/team_id foreign keys while the uniques are dropped.
        Schema::table('invites', function (Blueprint $table) {
            $table->index(['space_id', 'email']);
            $table->index(['team_id', 'email']);
        });

        Schema::table('invites', function (Blueprint $table) {
            $table->dropUnique(['space_id', 'email']);
            $table->dropUnique(['team_id', 'email']);

            $table->timestamp('declined_at')->nullable()->after('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropColumn('declined_at');
            $table->unique(['space_id', 'email']);
            $table->unique(['team_id', 'email']);
        });

        Schema::table('invites', function (Blueprint $table) {
            $table->dropIndex(['space_id', 'email']);
            $table->dropIndex(['team_id', 'email']);
        });
    }
};
