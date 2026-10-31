<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Indexes for the asset usage lookup (AssetUsageService):
 *
 *  - contents (current_version_id) and (published_version_id): "is this version
 *    a live pointer" checks run per matched version.
 *  - a multi-valued index on content_versions.asset_ids (MySQL only, not MariaDB
 *    or SQLite), used by ContentVersion::scopeReferencingAssets(). It
 *    rejects values longer than 26 characters, so non-ULID entries are dropped
 *    from asset_ids first. Asset keys are ULIDs, so those never resolved anyway.
 *
 * Applied via spaces:repair-databases, which then rebuilds all reference ids.
 */
return new class extends Migration
{
    private const MULTI_VALUED_INDEX = 'content_versions_asset_ids_index';

    public function up(): void
    {
        if (! Schema::hasTable('contents') || ! Schema::hasTable('content_versions')) {
            return;
        }

        foreach (['current_version_id', 'published_version_id'] as $column) {
            if (! Schema::hasIndex('contents', [$column])) {
                Schema::table('contents', fn (Blueprint $table) => $table->index([$column], "contents_{$column}_index"));
            }
        }

        if (! $this->supportsMultiValuedIndexes() || Schema::hasIndex('content_versions', self::MULTI_VALUED_INDEX)) {
            return;
        }

        DB::table('content_versions')
            ->select(['id', 'asset_ids'])
            ->whereNotNull('asset_ids')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $ids = json_decode($row->asset_ids, true);
                    $ids = \is_array($ids) ? $ids : [];
                    $valid = array_values(array_filter($ids, static fn (mixed $id): bool => \is_string($id) && Str::isUlid($id)));

                    if ($valid !== $ids) {
                        DB::table('content_versions')->where('id', $row->id)->update(['asset_ids' => json_encode($valid)]);
                    }
                }
            });

        DB::statement(sprintf(
            "alter table `content_versions` add index `%s` ((cast(`asset_ids`->'$' as char(26) array)))",
            self::MULTI_VALUED_INDEX,
        ));
    }

    public function down(): void
    {
        if (Schema::hasTable('content_versions') && Schema::hasIndex('content_versions', self::MULTI_VALUED_INDEX)) {
            Schema::table('content_versions', fn (Blueprint $table) => $table->dropIndex(self::MULTI_VALUED_INDEX));
        }

        foreach (['current_version_id', 'published_version_id'] as $column) {
            if (Schema::hasTable('contents') && Schema::hasIndex('contents', "contents_{$column}_index")) {
                Schema::table('contents', fn (Blueprint $table) => $table->dropIndex("contents_{$column}_index"));
            }
        }
    }

    private function supportsMultiValuedIndexes(): bool
    {
        $connection = DB::connection();

        return $connection instanceof MySqlConnection && ! $connection->isMaria();
    }
};
