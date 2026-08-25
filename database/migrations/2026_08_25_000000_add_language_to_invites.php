<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            // Language of the invitation mail. Null on rows created before
            // the column existed; senders fall back to the app locale.
            $table->string('language', 5)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
