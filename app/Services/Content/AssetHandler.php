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
     * Ids of the assets a content payload references. Only ULID strings are
     * kept: asset keys are ULIDs, and spaces that still carry the old
     * multi-valued index on `content_versions.asset_ids` reject longer values,
     * which would fail the save.
     *
     * @return list<string>
     */
    public function extractContentAssets(array $data): array
    {
        return array_values(array_filter(
            $this->extractMatchingField($data, ['type' => 'asset'], 'id'),
            static fn (mixed $id): bool => \is_string($id) && Str::isUlid($id),
        ));
    }

    /**
     * Point asset references at other asset ids, e.g. when content moves to a
     * space where the same assets were created under new keys. Ids missing
     * from the map stay as they are.
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
                $id = $src['id'] ?? null;

                return \is_string($id) && isset($idMap[$id])
                    ? array_replace($src, ['id' => $idMap[$id]])
                    : $src;
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
                /** @var Asset $asset */
                $id = $src['id'] ?? null;
                $asset = is_string($id) || is_int($id) ? $assets->get($id) : null;

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
                $id = $src['id'] ?? null;
                $asset = is_string($id) || is_int($id) ? $assets->get($id) : null;
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
                }

                return $src;
            },
        );
    }
}
