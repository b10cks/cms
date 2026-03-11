<?php

namespace App\Services\Content;

use App\Models\Space\Asset;
use App\Models\Space\Content;
use Illuminate\Support\Collection;

class AssetHandler
{
    use ContentExtractor;
    use ContentReplacer;

    public function extractContentAssets(array $data): array
    {
        return $this->extract($data, [
            'type' => 'asset'
        ], 'id');
    }

    public function updateContentAssets(array $data, Collection $assets): array
    {
        return $this->replace($data, [
            'type' => 'asset'
        ], function ($src) use ($assets) {
            /** @var Asset $asset */
            $asset = $assets->firstWhere('id', $src['id'] ?? null);

            return $asset ? \Arr::only($asset->append(['full_path'])->toArray(), [
                'id',
                'full_path',
                'extension',
                'mime_type',
                'size',
                'filename'
            ]) + $src + ['url' => $asset->getUrl(),] : $src;
        });
    }

    public function replaceContentAssets(Content|\App\Http\Resources\Api\ContentResource $content, $data, Collection $assets): array
    {
        return $this->replace($data, [
            'type' => 'asset'
        ], function ($src) use ($assets, $content) {
            $asset = $assets->firstWhere('id', $src['id'] ?? null);
            if ($asset) {
                $assetTranslationFields = $content->i18n_parent ? data_get($asset, "data.fields.{$content->language_iso}", []) : [];
                $assetFields = $assetTranslationFields + data_get($asset, 'data.fields._default', []);
                $result = $assetFields + ($src['data'] ?? []);
                $result['focus'] = data_get($src, 'data.focus', data_get($src, 'data.focus', null));

                $src = [
                    'url' => $asset->getUrl(),
                    'data' => $result,
                    'metadata' => \Arr::only($asset->metadata, ['width', 'height', 'thumbnails', 'duration']),
                ] + $src;
            }

            return $src;
        });
    }
}
