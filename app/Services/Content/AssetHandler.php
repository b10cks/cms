<?php

namespace App\Services\Content;

use App\Http\Resources\Api\ContentResource;
use App\Models\Space\Asset;
use App\Models\Space\Content;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AssetHandler
{
    use ContentExtractor;
    use ContentReplacer;

    /**
     * Ids of the assets a content payload references. Only ULID strings are
     * kept: asset keys are ULIDs, and the multi-valued index on
     * `content_versions.asset_ids` rejects longer values, which would fail the save.
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

                return $asset ? \Arr::only($asset->append(['full_path'])->toArray(), [
                    'id',
                    'full_path',
                    'extension',
                    'mime_type',
                    'size',
                    'filename',
                ]) + $src + ['url' => $asset->getUrl()] : $src;
            },
        );
    }

    public function replaceContentAssets(Content|ContentResource $content, $data, Collection $assets): array
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
            function ($src) use ($assets, $content) {
                $id = $src['id'] ?? null;
                $asset = is_string($id) || is_int($id) ? $assets->get($id) : null;
                if ($asset) {
                    $assetTranslationFields = $content->i18n_parent_id !== null
                        ? data_get($asset, "data.fields.{$content->language_iso}", [])
                        : [];
                    $assetFields = $assetTranslationFields + data_get($asset, 'data.fields._default', []);
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
