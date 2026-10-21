<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notification list filters by notifiable and sorts by created_at. Extending the
 * morph index with created_at serves both and replaces it.
 */
return new class extends Migration
{
    private const MORPH_INDEX = 'notifications_notifiable_type_notifiable_id_index';

    private const NEW_COLUMNS = ['notifiable_type', 'notifiable_id', 'created_at'];

    public function up(): void
    {
        if (! Schema::hasIndex('notifications', self::NEW_COLUMNS)) {
            Schema::table('notifications', fn (Blueprint $table) => $table->index(self::NEW_COLUMNS));
        }

        if (Schema::hasIndex('notifications', self::MORPH_INDEX)) {
            Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex(self::MORPH_INDEX));
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('notifications', self::MORPH_INDEX)) {
            Schema::table('notifications', fn (Blueprint $table) => $table->index(['notifiable_type', 'notifiable_id'], self::MORPH_INDEX));
        }

        if (Schema::hasIndex('notifications', self::NEW_COLUMNS)) {
            Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex(self::NEW_COLUMNS));
        }
    }
};
