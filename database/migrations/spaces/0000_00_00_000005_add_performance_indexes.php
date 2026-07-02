<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds indexes that hot query paths depend on but the original space schema
 * lacked:
 *
 *  - redirects.source is looked up on every content-delivery request; without
 *    an index it forced a full table scan of redirects per request.
 *  - external_id is the cross-space identity key and is queried per-row during
 *    space migrations; without an index each lookup scanned the target table
 *    (effectively O(n²) migrations).
 *
 * Applied to every space database (new databases at creation, existing ones via
 * spaces:repair-databases), and tracked per-database so it runs at most once.
 */
return new class extends Migration
{
    /**
     * Tables that carry an external_id column.
     *
     * @var array<int, string>
     */
    private array $externalIdTables = [
        'asset_folders', 'asset_tags', 'assets', 'asset_versions', 'icons',
        'block_folders', 'block_tags', 'blocks', 'releases', 'contents',
        'content_versions', 'redirects', 'data_sources', 'data_entries', 'comments',
    ];

    public function up(): void
    {
        if (Schema::hasTable('redirects')
            && Schema::hasColumn('redirects', 'source')
            && ! Schema::hasIndex('redirects', ['source'])) {
            Schema::table('redirects', function (Blueprint $table) {
                $table->index('source');
            });
        }

        foreach ($this->externalIdTables as $tableName) {
            if (! Schema::hasTable($tableName)
                || ! Schema::hasColumn($tableName, 'external_id')
                || Schema::hasIndex($tableName, ['external_id'])) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->index('external_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('redirects') && Schema::hasIndex('redirects', ['source'])) {
            Schema::table('redirects', function (Blueprint $table) {
                $table->dropIndex(['source']);
            });
        }

        foreach ($this->externalIdTables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasIndex($tableName, ['external_id'])) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['external_id']);
            });
        }
    }
};
