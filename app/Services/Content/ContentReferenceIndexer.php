<?php

namespace App\Services\Content;

use App\Models\Space\ContentVersion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rebuilds `asset_ids`, `link_ids` and `relation_ids` on every content version
 * of the current space from the version payload. The saving hook keeps them in
 * sync for model writes; this repairs rows written around the model (space
 * migrations, blueprints, raw imports) and rows saved before the extractors
 * changed. Bind the space with `SpaceContext::enter()` first.
 */
class ContentReferenceIndexer
{
    private const REFERENCE_COLUMNS = ['asset_ids', 'link_ids', 'relation_ids'];

    /**
     * @return int number of versions whose reference ids changed
     */
    public function reindex(): int
    {
        $changed = 0;

        ContentVersion::query()
            ->select(['id', 'content_id', 'content', ...self::REFERENCE_COLUMNS])
            // Trashed entries and blocks still resolve, or a reindex would wipe
            // the relation ids of everything in the trash.
            ->with(['contentModel' => fn (BelongsTo $query) => $query->withTrashed()->with([
                'block' => fn (BelongsTo $query) => $query->withTrashed(),
            ])])
            ->chunkById(200, function ($versions) use (&$changed): void {
                foreach ($versions as $version) {
                    $before = $version->only(self::REFERENCE_COLUMNS);
                    $version->refreshReferenceIds();
                    $after = $version->only(self::REFERENCE_COLUMNS);

                    if ($after === $before) {
                        continue;
                    }

                    // A base update, so the payload is not rewritten and no
                    // model, audit or automation events fire for a repair.
                    ContentVersion::query()->whereKey($version->id)->toBase()->update(
                        array_map(static fn (?array $ids): string => json_encode($ids ?? []), $after),
                    );
                    $changed++;
                }
            });

        return $changed;
    }
}
