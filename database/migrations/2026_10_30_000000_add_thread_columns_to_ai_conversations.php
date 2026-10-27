<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Conversations become threads: chat, a schedule's standing thread or the space's suggestions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            // The suggestions and schedule threads have no owner.
            $table->ulid('user_id')->nullable()->change();
            $table->string('kind', 20)->default('chat')->after('user_id');
            $table->foreignUlid('schedule_id')->nullable()->unique()->after('kind')->constrained('ai_schedules')->nullOnDelete();
            $table->boolean('shared')->default(false)->after('title');
            // Started from an inline surface: listed, never raising attention.
            $table->boolean('quiet')->default(false)->after('shared');
            $table->timestamp('archived_at')->nullable()->after('quiet');
            $table->timestamp('last_activity_at')->nullable()->after('archived_at');
            // The screen chips the thread started with.
            $table->longText('context')->nullable()->after('last_activity_at');
            $table->index(['space_id', 'last_activity_at']);
        });

        DB::table('ai_conversations')->update(['last_activity_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropIndex(['space_id', 'last_activity_at']);
            $table->dropConstrainedForeignId('schedule_id');
            $table->dropColumn(['kind', 'shared', 'quiet', 'archived_at', 'last_activity_at', 'context']);
        });
    }
};
