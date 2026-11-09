<?php

namespace App\Services\Content;

use App\Models\Space\Asset;

/**
 * Checks content asset references against the assets of the current space.
 * Bind the space with `SpaceContext::enter()` first.
 */
class AssetReferenceResolver
{
    public function __construct(private readonly AssetHandler $assets) {}

    /**
     * The ids among `$candidates` that name an asset of this space. Trashed
     * assets count: their row stays, and delivery keeps serving the stored
     * snapshot of a reference to one, so content using it stays publishable.
     *
     * @param  iterable<string>  $candidates
     * @return list<string>
     */
    public function existing(iterable $candidates): array
    {
        $candidates = array_values(array_unique([...$candidates]));

        return $candidates === []
            ? []
            : Asset::withTrashed()->whereIn('id', $candidates)->pluck('id')->all();
    }

    /**
     * Point every asset reference at the asset it names (see
     * `AssetHandler::canonicalizeContentAssets()`), with one lookup.
     * `dangling` lists the reference ids that still name no asset.
     *
     * @return array{content: array<array-key, mixed>, dangling: list<string>}
     */
    public function normalize(array $data): array
    {
        $known = $this->existing($this->assets->extractContentAssets($data));
        $content = $this->assets->canonicalizeContentAssets($data, $known);

        return ['content' => $content, 'dangling' => $this->assets->unknownReferenceIds($content, $known)];
    }
}
