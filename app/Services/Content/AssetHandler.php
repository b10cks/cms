<?php

namespace App\Services\Content;

use App\Models\Space\Asset;
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
            ]) + $src + ['url' => $asset->getUrl(),]: $src;
        });
    }

    public function replaceContentAssets(array $data, Collection $assets): array
    {
        return $this->replace($data, [
            'type' => 'asset'
        ], function ($src) use ($assets) {
            $asset = $assets->firstWhere('id', $src['id'] ?? null);
            if ($asset) {
                $data = array_merge($asset['data'] ?? [], $src['data'] ?? []);
                $src = [
                        'url' => $asset->getUrl(),
                        'data' => $data,
                        'metadata' => \Arr::only($asset->metadata, ['width', 'height', 'thumbnails', 'duration']),
                    ] + $src;
            }

            return $src;
        });
    }
}
