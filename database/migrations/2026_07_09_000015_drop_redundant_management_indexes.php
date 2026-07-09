<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops plain indexes that duplicate a unique constraint on the exact same tuple
 * (roles / team_user / space_user). The unique already provides the index, so the
 * second index is pure write/storage overhead.
 *
 * The create migration was adapted so fresh databases never build these; this
 * migration self-heals already-migrated databases. Guarded and dropped by index
 * NAME rather than columns on purpose: the surviving unique covers the same
 * columns, so a column-based hasIndex() check would match the unique and
 * false-positive. Idempotent — a no-op where the index is already gone.
 */
return new class extends Migration
{
    /**
     * table => [index name => columns to rebuild on rollback]
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $indexes = [
        'roles' => [
            'roles_scope_team_id_key_index' => ['scope', 'team_id', 'key'],
        ],
        'team_user' => [
            'team_user_team_id_user_id_index' => ['team_id', 'user_id'],
        ],
        'space_user' => [
            'space_user_space_id_user_id_index' => ['space_id', 'user_id'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $tableName => $names) {
            foreach ($names as $indexName => $columns) {
                if (! Schema::hasTable($tableName) || ! Schema::hasIndex($tableName, $indexName)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $tableName => $names) {
            foreach ($names as $indexName => $columns) {
                if (! Schema::hasTable($tableName) || Schema::hasIndex($tableName, $indexName)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
                    $table->index($columns, $indexName);
                });
            }
        }
    }
};
