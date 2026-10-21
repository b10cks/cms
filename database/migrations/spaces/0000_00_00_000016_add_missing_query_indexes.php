<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-heals space databases that never got indexes added to the create migration
 * after they were migrated, and adds indexes for the mgmt list sorts:
 *
 *  - contents (i18n_parent_id, deleted_at), (parent_id, language_iso, slug, deleted_at)
 *    and blocks (type, deleted_at): added to 0000_00_00_000004 in April, so only
 *    databases created since have them. The i18n family lookup
 *    (id IN (...) OR i18n_parent_id IN (...)) was a full scan without the first.
 *  - assets (deleted_at, created_at) and (folder_id, deleted_at, created_at): the
 *    default asset grid sort. The latter replaces (folder_id, deleted_at) from 000006.
 *  - content_versions (content_id, created_at): replaces the single (content_id) index.
 *
 * Each index is added only when no index on exactly those columns exists, so this is a
 * no-op where one is already present. A replaced index is dropped only after its
 * replacement exists. Applied via spaces:repair-databases.
 */
return new class extends Migration
{
    /**
     * table => [index name => columns]
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $add = [
        'contents' => [
            'contents_i18n_parent_id_deleted_at_index' => ['i18n_parent_id', 'deleted_at'],
            'contents_parent_language_slug_deleted_at_index' => ['parent_id', 'language_iso', 'slug', 'deleted_at'],
        ],
        'blocks' => [
            'blocks_type_deleted_at_index' => ['type', 'deleted_at'],
        ],
        'assets' => [
            'assets_deleted_at_created_at_index' => ['deleted_at', 'created_at'],
            'assets_folder_id_deleted_at_created_at_index' => ['folder_id', 'deleted_at', 'created_at'],
        ],
        'content_versions' => [
            'content_versions_content_id_created_at_index' => ['content_id', 'created_at'],
        ],
    ];

    /**
     * Replaced index name => its table, columns and the columns of the superseding index.
     *
     * @var array<string, array{table: string, columns: array<int, string>, replacedBy: array<int, string>}>
     */
    private array $replaced = [
        'assets_folder_id_deleted_at_index' => [
            'table' => 'assets',
            'columns' => ['folder_id', 'deleted_at'],
            'replacedBy' => ['folder_id', 'deleted_at', 'created_at'],
        ],
        'content_versions_content_id_index' => [
            'table' => 'content_versions',
            'columns' => ['content_id'],
            'replacedBy' => ['content_id', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->add as $tableName => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! $this->tableHasColumns($tableName, $columns) || Schema::hasIndex($tableName, $columns)) {
                    continue;
                }

                Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
            }
        }

        foreach ($this->replaced as $name => $spec) {
            if (! Schema::hasTable($spec['table'])
                || ! Schema::hasIndex($spec['table'], $name)
                || ! Schema::hasIndex($spec['table'], $spec['replacedBy'])) {
                continue;
            }

            Schema::table($spec['table'], fn (Blueprint $table) => $table->dropIndex($name));
        }
    }

    public function down(): void
    {
        foreach ($this->replaced as $name => $spec) {
            if (! $this->tableHasColumns($spec['table'], $spec['columns']) || Schema::hasIndex($spec['table'], $name)) {
                continue;
            }

            Schema::table($spec['table'], fn (Blueprint $table) => $table->index($spec['columns'], $name));
        }

        // 000004 creates the contents and blocks indexes on fresh databases, so only drop what this migration owns.
        $owned = [
            'assets' => ['assets_deleted_at_created_at_index', 'assets_folder_id_deleted_at_created_at_index'],
            'content_versions' => ['content_versions_content_id_created_at_index'],
        ];

        foreach ($owned as $tableName => $names) {
            foreach ($names as $name) {
                if (Schema::hasTable($tableName) && Schema::hasIndex($tableName, $name)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($name));
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function tableHasColumns(string $tableName, array $columns): bool
    {
        return Schema::hasTable($tableName) && Schema::hasColumns($tableName, $columns);
    }
};
