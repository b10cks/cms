<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional "access until" dates. `memberships:expire` removes a membership
     * once its date has passed. Invites already use `expires_at` for the link
     * itself, so the access date they hand on is `access_expires_at`.
     */
    public function up(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index();
        });

        Schema::table('space_user', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index();
        });

        Schema::table('invites', function (Blueprint $table) {
            $table->timestamp('access_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });

        Schema::table('space_user', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });

        Schema::table('invites', function (Blueprint $table) {
            $table->dropColumn('access_expires_at');
        });
    }
};
