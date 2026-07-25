<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Asset\AssetMetadataFieldResolver;
use App\Services\Storage\AssetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Asset
 */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Space|null $space */
        $space = $request->route('space');

        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'filename' => $this->filename,
            'extension' => $this->extension,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'full_path' => $this->full_path,
            'folder_id' => $this->folder_id,
            'folder' => $this->whenLoaded('folder', fn () => new AssetFolderResource($this->folder)),
            'metadata' => $this->enrichMetadata($this->metadata),
            'data' => $this->data && count($this->data) ? $this->data : new \StdClass,
            'tags' => $this->tags,
            'license_expires_at' => $this->license_expires_at?->toIso8601String(),
            'rights_status' => $this->rights_status,
            'linked_contents_count' => (int) ($this->resource->getAttributes()['linked_contents_count'] ?? 0),
            'effective_asset_fields' => $this->resolveEffectiveAssetFields($request, $space),
            'url' => app(AssetService::class)->getAssetUrl($this->resource),
            'poster_url' => app(AssetService::class)->getPosterUrl($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function enrichMetadata(?array $metadata): ?array
    {
        if (empty($metadata['thumbnails'])) {
            return $metadata;
        }

        $metadata['thumbnails'] = array_map(function (array $thumb) {
            $thumb['full_path'] = $this->storage_id . '/' . $thumb['path'];

            return $thumb;
        }, $metadata['thumbnails']);

        return $metadata;
    }

    private function resolveEffectiveAssetFields(Request $request, ?Space $space): array
    {
        if (! $space instanceof Space) {
            return [];
        }

        $preloadedFields = $this->resource->getAttributes()['effective_asset_fields'] ?? null;
        if (\is_array($preloadedFields)) {
            return $preloadedFields;
        }

        return $this->resolveMetadataFieldResolver($request)
            ->getEffectiveFieldsForAsset($space, $this->resource);
    }

    private function resolveMetadataFieldResolver(Request $request): AssetMetadataFieldResolver
    {
        $resolver = $request->attributes->get(AssetMetadataFieldResolver::class);

        if ($resolver instanceof AssetMetadataFieldResolver) {
            return $resolver;
        }

        $resolver = app(AssetMetadataFieldResolver::class);
        $request->attributes->set(AssetMetadataFieldResolver::class, $resolver);

        return $resolver;
    }
}
