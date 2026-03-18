<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\AssetHandler;
use App\Services\Content\ContentI18nService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Content
 */
class ContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Space|null $space */
        $space = $request->route('space');
        $i18nService = app(ContentI18nService::class);

        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'block_id' => $this->block_id,
            'block' => $this->whenLoaded('block', fn () => [
                'id' => $this->block->id,
                'name' => $this->block->name,
                'icon' => $this->block->icon,
                'slug' => $this->block->slug,
            ]),
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => [
                'id' => $this->parent->id,
                'name' => $this->parent->name,
                'slug' => $this->parent->slug,
            ]),
            'name' => $this->name,
            'children_count' => $this->whenCounted('children', fn () => $this->children_count),
            'slug' => $this->slug,
            'full_slug' => $this->full_slug,
            'language_iso' => $this->language_iso,
            'i18n_parent_id' => $this->i18n_parent_id,
            'i18n_canonical_id' => $i18nService->getCanonicalId($this->resource),
            'effective_i18n_mode' => $space ? $i18nService->resolveEffectiveMode($space, $this->resource) : 'overlay',
            'language_versions' => $space ? $i18nService->buildLanguageVersions($space, $this->resource) : [],
            'content' => $this->prepareContent(),
            'settings' => $this->settings->toArray() ?: new \StdClass,
            'current_version_id' => $this->current_version_id,
            'current_version' => $this->whenLoaded('current_version', fn () => new ContentVersionListResource($this->current_version)),
            'published_version_id' => $this->published_version_id,
            'published_version' => $this->whenLoaded('published_version', fn () => new ContentVersionListResource($this->published_version)),
            'published_at' => $this->published_at?->toIso8601String(),
            'first_published_at' => $this->first_published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'i18n_parent' => $this->whenLoaded(
                'i18n_parent',
                fn () => new ContentTranslationResource($this->i18n_parent)
            ),
            'i18n_translations' => $this->whenLoaded(
                'i18n_children',
                fn () => ContentTranslationResource::collection($this->i18n_children)
            ),
            'i18n_siblings' => $this->whenLoaded(
                'i18n_siblings',
                fn () => ContentTranslationResource::collection($this->i18n_siblings)
            ),
        ];
    }

    protected function prepareContent()
    {
        $content = $this->current_version?->content;

        return $content ? $this->injectData($content) : new \StdClass;
    }

    protected function injectData(array $content)
    {
        $assets = $this->whenLoaded('assets', fn () => $this->assets, $this->current_version?->assets);

        return app(AssetHandler::class)->updateContentAssets($content, $assets);
    }
}
