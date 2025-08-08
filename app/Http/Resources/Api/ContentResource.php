<?php

namespace App\Http\Resources\Api;

use App\Models\Space\Content;
use App\Services\Content\AssetHandler;
use App\Services\Content\LinkHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Content
 */
class ContentResource extends JsonResource
{
    public function toArray(Request $request)
    {
        $this->additional([
            'rv' => $request->space->rv
        ]);

        return [
            'id' => $this->getRouteKey(),
            'name' => $this->name,
            'slug' => $this->slug,
            'block' => $this->whenLoaded('block', fn () => $this->block->slug),
            'parent_id' => $this->parent_id,
            'full_slug' => $this->full_slug,
            'content' => $this->getTransformedContent(),
            'language_iso' => $this->language_iso,
            'translations' => $this->handleTranslations(),
//            'links' => $this->handleLinks(),
//            'assets' => $this->handleAssets(),
//            'relations' => $this->handleRelations(),
            'published_at' => $this->published_at?->toIso8601String(),
            'first_published_at' => $this->first_published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected function getTransformedContent()
    {
        $content = $this->getContent();
        if (!$content) {
            return new \stdClass();
        }

        $this->injectData($content);

        return [
            ...$content,
            'block' => $this->block->slug,
        ];
    }

    protected function handleTranslations()
    {
        if (!$this->resource->relationLoaded('i18n_children') ||
            !$this->resource->relationLoaded('i18n_siblings') ||
            !$this->resource->relationLoaded('i18n_parent')) {
            return [];
        }

        $alternatives = [];
        if ($this->resource->i18n_parent) {
            $alternatives[] = $this->resource->i18n_parent;
        }
        $alternatives = array_merge($alternatives, $this->resource->i18n_children->all());
        $alternatives = array_merge($alternatives, $this->resource->i18n_siblings->all());

        return SimpleContentResource::collection($alternatives);
    }

    protected function handleLinks()
    {
        if (!$this->resource->relationLoaded('links')) {
            return [];
        }

        return SimpleContentResource::collection($this->resource->links);
    }

    protected function handleRelations()
    {
        if (!$this->resource->relationLoaded('relations')) {
            return [];
        }

        return SimpleContentResource::collection($this->resource->relations);
    }

    protected function injectData(array &$content)
    {
        $content = app(LinkHandler::class)->replaceContentLinks($content, $this->resource->links);
        $content = app(AssetHandler::class)->replaceContentAssets($content, $this->resource->assets);
    }
}
