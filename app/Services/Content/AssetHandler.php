<?php

namespace App\Services\Content;

use App\Models\Space\Asset;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AssetHandler
{
    use ContentExtractor;
    use ContentReplacer;

    /**
     * Ids of the assets a content payload references, see `referencedIds()`.
     * Only ULID strings are kept: asset keys are ULIDs, and spaces that still
     * carry the old multi-valued index on `content_versions.asset_ids` reject
     * longer values, which would fail the save.
     *
     * @return list<string>
     */
    public function extractContentAssets(array $data): array
    {
        return array_values(array_filter(
            $this->extract(
                $data,
                fn (array $value): ?array => $this->matchesContentCriteria($value, ['type' => 'asset'])
                    ? $this->referencedIds($value)
                    : null,
            ),
            static fn (mixed $id): bool => \is_string($id) && Str::isUlid($id),
        ));
    }

    /**
     * Point asset references at other asset ids, e.g. when content moves to a
     * space where the same assets were created under new keys. A reference
     * maps through the first of its `referencedIds()` in the map; references
     * with none stay as they are.
     *
     * @param  array<string, string>  $idMap  old asset id => new asset id
     */
    public function remapContentAssets(array $data, array $idMap): array
    {
        if ($idMap === []) {
            return $data;
        }

        return $this->replaceMatching(
            $data,
            ['type' => 'asset'],
            function (array $src) use ($idMap): array {
                foreach ($this->referencedIds($src) as $id) {
                    if (isset($idMap[$id])) {
                        return array_replace($src, ['id' => $idMap[$id]]);
                    }
                }

                return $src;
            },
        );
    }

    /**
     * Point every asset reference at the asset it actually names: its `id`
     * when that is one of `$assetIds`, else the `full_path` asset when that
     * is. References matching neither stay as they are.
     *
     * @param  list<string>  $assetIds  ids of the assets that exist
     */
    public function canonicalizeContentAssets(array $data, array $assetIds): array
    {
        $known = array_flip($assetIds);

        return $this->replaceMatching(
            $data,
            ['type' => 'asset'],
            function (array $src) use ($known): array {
                foreach ($this->referencedIds($src) as $id) {
                    if (isset($known[$id])) {
                        return ($src['id'] ?? null) === $id ? $src : array_replace($src, ['id' => $id]);
                    }
                }

                return $src;
            },
        );
    }

    public function updateContentAssets(array $data, Collection $assets): array
    {
        $assets = $assets->reverse()->keyBy('id');

        return $this->replaceMatching(
            $data,
            [
                'type' => 'asset',
            ],
            function ($src) use ($assets) {
                $asset = $this->referencedAsset($src, $assets);

                if (! $asset) {
                    return $src;
                }

                // Pixels let the editor's asset rows read "JPG, 3501 × 2481, 1.9 MB".
                $pixels = \Arr::only($asset->metadata ?? [], ['width', 'height']);

                return \Arr::only($asset->append(['full_path'])->toArray(), [
                    'id',
                    'full_path',
                    'extension',
                    'mime_type',
                    'size',
                    'filename',
                ]) + ($pixels ? ['metadata' => $pixels] : []) + $src + ['url' => $asset->getUrl()];
            },
        );
    }

    /**
     * Overlay live asset data onto delivered content. `$languages` lists the
     * asset field translations to use, most specific first; `_default` fills
     * whatever they leave out.
     *
     * @param  list<string>  $languages
     */
    public function replaceContentAssets(array $languages, $data, Collection $assets): array
    {
        if ($assets->isEmpty()) {
            return $data;
        }

        $assets = $assets->reverse()->keyBy('id');

        return $this->replaceMatching(
            $data,
            [
                'type' => 'asset',
            ],
            function ($src) use ($assets, $languages) {
                $asset = $this->referencedAsset($src, $assets);
                if ($asset) {
                    $assetFields = data_get($asset, 'data.fields._default', []);
                    foreach (array_reverse($languages) as $language) {
                        $assetFields = data_get($asset, "data.fields.{$language}", []) + $assetFields;
                    }
                    $result = $assetFields + ($src['data'] ?? []);
                    $result['focus'] = data_get($src, 'data.focus', data_get($asset, 'data.focus', null));

                    $metadata = \Arr::only($asset->metadata, ['width', 'height', 'thumbnails', 'duration', 'dominant_color', 'a11y']);
                    if (! empty($metadata['thumbnails'])) {
                        $metadata['thumbnails'] = array_map(function (array $thumb) use ($asset) {
                            $thumb['full_path'] = $asset->storage_id.'/'.$thumb['path'];

                            return $thumb;
                        }, $metadata['thumbnails']);
                    }

                    $src =
                        [
                            'url' => $asset->getUrl(),
                            'data' => $result,
                            'full_path' => $asset->full_path,
                            'filename' => $asset->filename,
                            'extension' => $asset->extension,
                            'mime_type' => $asset->mime_type,
                            'size' => $asset->size,
                            'metadata' => $metadata,
                        ] + $src;
                    // In place, so the delivered key order stays as it was.
                    $src['id'] = $asset->id;
                }

                return $src;
            },
        );
    }

    /**
     * Asset ids a reference may point at, most trusted first: its `id`, then
     * the asset id in its `full_path` (`{storage}/{space}/{asset}/{file}`).
     * Pasting blocks once gave asset references fresh ids; the path still
     * names the real asset.
     *
     * @return list<string|int>
     */
    private function referencedIds(array $reference): array
    {
        $id = $reference['id'] ?? null;
        $path = $reference['full_path'] ?? null;
        $pathId = \is_string($path) ? (explode('/', $path)[2] ?? null) : null;

        return array_values(array_unique(array_filter(
            [$id, $pathId],
            static fn (mixed $candidate): bool => (\is_string($candidate) && $candidate !== '') || \is_int($candidate),
        )));
    }

    /**
     * @param  Collection<array-key, Asset>  $assets  keyed by id
     */
    private function referencedAsset(array $reference, Collection $assets): ?Asset
    {
        foreach ($this->referencedIds($reference) as $id) {
            if ($asset = $assets->get($id)) {
                return $asset;
            }
        }

        return null;
    }
}
