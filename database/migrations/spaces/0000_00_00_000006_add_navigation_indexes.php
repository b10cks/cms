<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds indexes for the primary navigation filters that the original space schema
 * left uncovered:
 *
 *  - contents.block_id — the sitemap's whereIn and the public ?content_type=
 *    filter select contents by block; without an index each was a full scan.
 *  - assets.folder_id — the asset manager's main folder navigation filter.
 *  - asset_folders.parent_id — the folder tree navigation.
 *
 * All three pair the filter column with deleted_at so the soft-delete scope stays
 * inside the index. Idempotent and applied to every space database (new databases
 * at creation, existing ones via spaces:repair-databases).
 */
return new class extends Migration
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $indexes = [
        'contents' => ['block_id', 'deleted_at'],
        'assets' => ['folder_id', 'deleted_at'],
        'asset_folders' => ['parent_id', 'deleted_at'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $tableName => $columns) {
            if (! $this->tableHasColumns($tableName, $columns)
                || Schema::hasIndex($tableName, $columns)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                $table->index($columns);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $tableName => $columns) {
            if (! Schema::hasTable($tableName) || ! Schema::hasIndex($tableName, $columns)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                $table->dropIndex($columns);
            });
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function tableHasColumns(string $tableName, array $columns): bool
    {
        if (! Schema::hasTable($tableName)) {
            return false;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return false;
            }
        }

        return true;
    }
};
