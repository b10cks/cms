<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\AssetHandler;
use App\Services\Content\ContentI18nResolver;
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
        $resolved = null;
        $resolvedRow = $this->resource;

        if ($space) {
            $resolved = app(ContentI18nResolver::class)->resolve(
                $space,
                $this->resource,
                $this->language_iso,
                'current',
            );
            $resolvedRow = $resolved->targetContent ?? $resolved->fallbackContent ?? $resolved->canonicalContent;
            $resolvedRow->loadMissing(['block', 'parent', 'current_version', 'published_version']);
        }

        $canonicalContent = $resolved?->canonicalContent ?? $this->resource;
        $effectiveI18nMode = $space
            ? ($resolved?->effectiveMode ?? $i18nService->resolveEffectiveMode($space, $this->resource))
            : 'overlay';
        $languageVersions = $space
            ? ($resolved
                ? $i18nService->buildLanguageVersionsFromFamily(
                    $space,
                    $this->resource,
                    $resolved->familyContents,
                    $canonicalContent,
                )
                : $i18nService->buildLanguageVersions($space, $this->resource))
            : [];

        return [
            'id' => $resolvedRow->id,
            'external_id' => $resolvedRow->external_id,
            'block_id' => $resolvedRow->block_id,
            'block' => $resolvedRow->block
                ? [
                    'id' => $resolvedRow->block->id,
                    'name' => $resolvedRow->block->name,
                    'icon' => $resolvedRow->block->icon,
                    'slug' => $resolvedRow->block->slug,
                ]
                : null,
            'block_schema' => $resolvedRow->block?->schema?->toArray(),
            'block_editor' => $resolvedRow->block?->editor ?? [],
            'parent_id' => $resolvedRow->parent_id,
            'position' => $resolvedRow->position,
            'parent' => $resolvedRow->parent
                ? [
                    'id' => $resolvedRow->parent->id,
                    'name' => $resolvedRow->parent->name,
                    'slug' => $resolvedRow->parent->slug,
                ]
                : null,
            'name' => $resolvedRow->name,
            'children_count' => $this->whenCounted('children', fn () => $this->children_count),
            'slug' => $resolvedRow->slug,
            'full_slug' => $resolvedRow->full_slug,
            'language_iso' => $resolved?->requestedLanguage ?? $resolvedRow->language_iso,
            'i18n_parent_id' => $resolvedRow->i18n_parent_id,
            'i18n_canonical_id' => $canonicalContent->id,
            'effective_i18n_mode' => $effectiveI18nMode,
            'language_versions' => $languageVersions,
            'content' => $this->prepareContent($resolved, $resolvedRow),
            'raw_content' => $resolvedRow->current_version?->content ?: new \StdClass,
            'settings' => $resolvedRow->settings->toArray() ?: new \StdClass,
            'current_version_id' => $resolvedRow->current_version_id,
            'current_version' => $resolvedRow->current_version ? new ContentVersionListResource($resolvedRow->current_version) : null,
            'published_version_id' => $resolvedRow->published_version_id,
            'published_version' => $resolvedRow->published_version ? new ContentVersionListResource($resolvedRow->published_version) : null,
            'published_at' => $resolvedRow->published_at?->toIso8601String(),
            'first_published_at' => $resolvedRow->first_published_at?->toIso8601String(),
            'created_at' => $resolvedRow->created_at?->toIso8601String(),
            'updated_at' => $resolvedRow->updated_at?->toIso8601String(),
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

    protected function prepareContent($resolved = null, ?Content $resolvedRow = null)
    {
        if ($resolved && $resolvedRow) {
            $content = $resolved->effectiveContent;

            return ! empty($content) ? $this->injectResolvedData($resolved, $resolvedRow, $content) : new \StdClass;
        }

        $content = $resolvedRow?->current_version?->content ?? $this->current_version?->content;

        return $content ? $this->injectData($content) : new \StdClass;
    }

    protected function injectData(array $content)
    {
        $assets = $this->whenLoaded('assets', fn () => $this->assets, $this->current_version?->assets);

        return app(AssetHandler::class)->updateContentAssets($content, $assets);
    }

    protected function injectResolvedData($resolved, Content $resolvedRow, array $content)
    {
        return app(AssetHandler::class)->updateContentAssets(
            $content,
            $resolvedRow->current_version?->assets ?? $resolved->effectiveAssets
        );
    }
}
