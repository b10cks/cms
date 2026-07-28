<?php

namespace App\Http\Resources\Api;

use App\Services\Content\BreadcrumbLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BreadcrumbLevel
 *
 * @resourceProperty id format=uuid Unique content identifier of the level.
 * @resourceProperty external_id External identifier of the level, if one is set.
 * @resourceProperty name Human-readable name in the resolved language.
 * @resourceProperty slug URL-safe slug of the level in the resolved language.
 * @resourceProperty full_slug Stored path of the level, without any locale segment.
 * @resourceProperty path Delivery path including the requested language's locale segment.
 * @resourceProperty block Slug of the assigned block definition.
 * @resourceProperty parent_id format=uuid Parent content identifier, null for the root level.
 * @resourceProperty position 0-based sort position within the parent.
 * @resourceProperty depth type=integer Depth in the content tree, 0 for the root level.
 * @resourceProperty is_root type=boolean Whether this level is a tree root.
 * @resourceProperty is_current type=boolean Whether this level is the requested entry itself.
 * @resourceProperty language_iso Requested language ISO code.
 * @resourceProperty resolved_language_iso ISO code of the language this level was actually served from.
 * @resourceProperty is_fallback type=boolean Whether the level fell back to another language.
 * @resourceProperty is_published type=boolean Whether the served row is published.
 * @resourceProperty published_at format=date-time Publication timestamp in ISO 8601 format.
 * @resourceProperty updated_at format=date-time Last update timestamp in ISO 8601 format.
 * @resourceProperty content type=object additionalProperties=true Overlay-resolved payload, only with `include_content`.
 * @resourceProperty translations type=array Published sibling translations, only with `translations`.
 */
class ContentBreadcrumbResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BreadcrumbLevel $level */
        $level = $this->resource;
        $row = $level->row;

        return [
            'id' => $row->getRouteKey(),
            'external_id' => $row->external_id,
            'name' => $row->name,
            'slug' => $row->slug,
            'full_slug' => $row->full_slug,
            'path' => $level->path,
            'block' => $row->getAttribute('block_slug') ?? $row->block?->slug,
            'parent_id' => $row->parent_id,
            'position' => $row->position,
            'depth' => $level->depth,
            'is_root' => $level->isRoot,
            'is_current' => $level->isCurrent,
            'language_iso' => $level->requestedLanguage,
            'resolved_language_iso' => $level->resolvedLanguage,
            'is_fallback' => $level->isFallback(),
            'is_published' => $row->published_at !== null && $row->published_version_id !== null,
            'published_at' => $row->published_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
            // Delegated so a breadcrumb payload is byte-for-byte the `content` of
            // the same entry on `contents/{slug}`, including `take`/`except`.
            'content' => $this->when(
                $level->resolved !== null,
                fn (): array|\stdClass => (new ContentResource($level->resolved))->toArray($request)['content'],
            ),
            'translations' => $this->when($level->translations !== null, $level->translations),
        ];
    }
}
