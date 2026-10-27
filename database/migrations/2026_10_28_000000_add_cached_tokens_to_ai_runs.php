<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->unsignedInteger('cached_tokens')->default(0)->after('output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn('cached_tokens');
        });
    }
};
