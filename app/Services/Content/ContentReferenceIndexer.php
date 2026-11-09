<?php

namespace App\Services\Content;

use App\Models\Space\Asset;
use App\Models\Space\ContentVersion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Rebuilds `asset_ids`, `link_ids` and `relation_ids` on every content version
 * of the current space from the version payload. The saving hook keeps them in
 * sync for model writes; this repairs rows written around the model (space
 * migrations, blueprints, raw imports) and rows saved before the extractors
 * changed. It also points asset references whose `id` names no asset at the
 * asset their `full_path` names (`AssetHandler::canonicalizeContentAssets()`).
 * Bind the space with `SpaceContext::enter()` first.
 */
class ContentReferenceIndexer
{
    private const REFERENCE_COLUMNS = ['asset_ids', 'link_ids', 'relation_ids'];

    public function __construct(private readonly AssetHandler $assets) {}

    /**
     * Versions changed counts any column; payloads counts the versions whose
     * asset references were rewritten, contents the entries they belong to.
     *
     * @param  bool  $dryRun  count what would change, write nothing
     * @return array{versions: int, payloads: int, contents: int}
     */
    public function reindex(bool $dryRun = false): array
    {
        $versionsChanged = 0;
        $payloadsChanged = 0;
        $contents = [];

        ContentVersion::query()
            ->select(['id', 'content_id', 'content', ...self::REFERENCE_COLUMNS])
            // Trashed entries and blocks still resolve, or a reindex would wipe
            // the relation ids of everything in the trash.
            ->with(['contentModel' => fn (BelongsTo $query) => $query->withTrashed()->with([
                'block' => fn (BelongsTo $query) => $query->withTrashed(),
            ])])
            ->chunkById(200, function (Collection $versions) use ($dryRun, &$versionsChanged, &$payloadsChanged, &$contents): void {
                $known = $this->existingAssetIds($versions);

                foreach ($versions as $version) {
                    $before = $version->only(self::REFERENCE_COLUMNS);
                    $payload = $version->content ?? [];
                    $canonical = $this->assets->canonicalizeContentAssets($payload, $known);
                    $rewritten = $canonical !== $payload;

                    if ($rewritten) {
                        $version->content = $canonical;
                    }

                    $version->refreshReferenceIds();
                    $after = $version->only(self::REFERENCE_COLUMNS);

                    if (! $rewritten && $after === $before) {
                        continue;
                    }

                    $versionsChanged++;
                    if ($rewritten) {
                        $payloadsChanged++;
                        $contents[$version->content_id] = true;
                    }

                    if ($dryRun) {
                        continue;
                    }

                    // A base update, so no model, audit or automation events
                    // fire for a repair.
                    ContentVersion::query()->whereKey($version->id)->toBase()->update([
                        ...array_map(static fn (?array $ids): string => json_encode($ids ?? []), $after),
                        ...($rewritten ? ['content' => json_encode($canonical)] : []),
                    ]);
                }
            });

        return ['versions' => $versionsChanged, 'payloads' => $payloadsChanged, 'contents' => \count($contents)];
    }

    /**
     * Which of the asset ids the versions' payloads name exist in this space,
     * trashed ones included: a trashed asset is still the one meant.
     *
     * @param  Collection<int, ContentVersion>  $versions
     * @return list<string>
     */
    private function existingAssetIds(Collection $versions): array
    {
        $candidates = $versions
            ->flatMap(fn (ContentVersion $version): array => $this->assets->extractContentAssets($version->content ?? []))
            ->unique()
            ->values();

        return $candidates->isEmpty()
            ? []
            : Asset::withTrashed()->whereIn('id', $candidates)->pluck('id')->all();
    }
}
