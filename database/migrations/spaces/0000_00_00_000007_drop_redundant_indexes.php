<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops indexes that only add write/storage overhead because another index on the
 * same table already covers them:
 *
 *  - audit_logs.(referenced_type) / (owner_type) / (operation) — each a redundant
 *    left-prefix of a composite; audit_logs takes an INSERT on every space-model
 *    write, so these cost the most.
 *  - asset_versions.(asset_id) — left-prefix of (asset_id, version_number).
 *  - comments.(content_id) — left-prefix of (content_id, is_resolved).
 *
 * The create migration was adapted so fresh databases never build these; this
 * migration self-heals already-migrated databases. Guarded by index name (not
 * columns) and idempotent, so it is a no-op where the index is already gone.
 * Applied to every space database via spaces:repair-databases.
 */
return new class extends Migration
{
    /**
     * table => [index name => columns to rebuild on rollback]
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $indexes = [
        'audit_logs' => [
            'audit_logs_referenced_type_index' => ['referenced_type'],
            'audit_logs_owner_type_index' => ['owner_type'],
            'audit_logs_operation_index' => ['operation'],
        ],
        'asset_versions' => [
            'asset_versions_asset_id_index' => ['asset_id'],
        ],
        'comments' => [
            'comments_content_id_index' => ['content_id'],
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
