<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Restores `contents.published_at` for entries a draft save silently
 * unpublished.
 *
 * UpdateContent used to null `published_at` whenever a save produced a new
 * draft version, using the column as "has no pending changes". The delivery
 * API later started reading it as "is live", so every entry that had been
 * edited but not republished dropped out of the published scope and 404'd —
 * even though `published_version_id` still pointed at a perfectly good
 * published version. UpdateContent no longer touches the column; this puts the
 * affected rows back.
 *
 * The two ways `published_at` can be null while `published_version_id` is set
 * are told apart by the version ids: an explicit unpublish leaves the current
 * version untouched, so `current_version_id` still equals
 * `published_version_id`; a draft save moves `current_version_id` on. Only the
 * latter is restored. Neither order of the two operations can be told apart
 * from the columns alone, so an entry that was unpublished and edited — in
 * either sequence — comes back live. Every restored id is logged so the
 * handful of those can be found and taken down again.
 *
 * Applied to every space database (new ones at creation, existing ones via
 * spaces:repair-databases), so it tolerates a database without the tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contents') || ! Schema::hasTable('content_versions')) {
            return;
        }

        $restored = [];

        DB::table('contents')
            ->select(['id', 'published_version_id'])
            ->whereNull('deleted_at')
            ->whereNull('published_at')
            ->whereNotNull('published_version_id')
            ->whereNotNull('current_version_id')
            ->whereColumn('current_version_id', '!=', 'published_version_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$restored): void {
                $publishedAt = DB::table('content_versions')
                    ->whereIn('id', collect($rows)->pluck('published_version_id')->all())
                    ->pluck('published_at', 'id');

                foreach ($rows as $row) {
                    // Versions predating the published_at column on versions, or
                    // a dangling pointer, leave nothing to restore from.
                    $date = $publishedAt[$row->published_version_id] ?? null;

                    if ($date === null) {
                        continue;
                    }

                    DB::table('contents')
                        ->where('id', $row->id)
                        ->update(['published_at' => $date]);

                    $restored[] = $row->id;
                }
            });

        if ($restored !== []) {
            Log::info('Restored published_at for entries unpublished by a draft save', [
                'connection' => DB::getDefaultConnection(),
                'count' => \count($restored),
                'content_ids' => $restored,
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible by design: nulling these again would re-hide live
        // entries, and the pre-migration state carried no record of which rows
        // were affected.
    }
};
