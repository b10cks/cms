<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Space;
use App\Models\Space\AssetFolder;
use App\Services\Asset\AssetMetadataFieldResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetFolder
 */
class AssetFolderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Space|null $space */
        $space = $request->route('space');

        return [
            'id' => $this->getRouteKey(),
            'external_id' => $this->external_id,
            'name' => $this->name,
            'icon' => $this->icon,
            'color' => $this->color,
            'description' => $this->description,
            'parent_id' => $this->parent_id,
            'settings' => $this->settings?->toArray() ?? [],
            'effective_asset_fields' => $this->resolveEffectiveAssetFields($request, $space),
            'children_count' => $this->whenCounted('children', fn() => $this->children_count),
            'assets_count' => $this->whenCounted('assets', fn() => $this->assets_count),
            'parent' => $this->whenLoaded('parent', fn() => new AssetFolderResource($this->parent)),
            'children' => $this->whenLoaded('children', fn() => AssetFolderResource::collection($this->children)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function resolveEffectiveAssetFields(Request $request, ?Space $space): array
    {
        if (!$space instanceof Space) {
            return [];
        }

        $preloadedFields = $this->resource->getAttributes()['effective_asset_fields'] ?? null;
        if (\is_array($preloadedFields)) {
            return $preloadedFields;
        }

        return $this->resolveMetadataFieldResolver($request)
            ->getEffectiveFieldsForFolder($space, $this->resource);
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
