<?php

namespace App\Http\Resources\Api;

use App\Models\Space\Content;
use App\Services\Content\AssetHandler;
use App\Services\Content\ContentFieldSelector;
use App\Services\Content\ContentI18nResolver;
use App\Services\Content\LinkHandler;
use App\Services\Content\ResolvedContent;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin Content
 *
 * @resourceProperty id format=uuid Unique content identifier.
 * @resourceProperty name Human-readable content name.
 * @resourceProperty slug URL slug of the resolved content.
 * @resourceProperty block Slug of the assigned block definition.
 * @resourceProperty parent_id format=uuid Parent content identifier if this content is nested.
 * @resourceProperty full_slug Full resolved path including all parent slugs.
 * @resourceProperty content type=object additionalProperties=true Effective content payload after i18n, link, and asset resolution.
 * @resourceProperty relations type=array items=ContentResource First-level related content entries when explicitly requested.
 * @resourceProperty language_iso Requested language ISO code used for content resolution.
 * @resourceProperty translations type=array items=SimpleContentResource Published sibling translations of the resolved content.
 * @resourceProperty published_at format=date-time Publication timestamp in ISO 8601 format.
 * @resourceProperty first_published_at format=date-time First publication timestamp in ISO 8601 format.
 * @resourceProperty created_at format=date-time Creation timestamp in ISO 8601 format.
 * @resourceProperty updated_at format=date-time Last update timestamp in ISO 8601 format.
 */
class ContentResource extends JsonResource
{
    public const string RELATION_RESOLUTION_MAX_DEPTH_ATTRIBUTE = 'api.content.resolve_relations.max_depth';

    public const string RELATION_RESOLUTION_DEPTH_ATTRIBUTE = 'api.content.resolve_relations.depth';

    public function toArray(Request $request)
    {
        $resolved = $this->resolveContent($request);
        $row = $resolved->targetContent ?? $resolved->fallbackContent ?? $resolved->canonicalContent;
        $row->loadMissing('block');

        $this->additional([
            'rv' => app('currentSpace')->rv,
        ]);

        return [
            'id' => $row->getRouteKey(),
            'name' => $row->name,
            'slug' => $row->slug,
            'block' => $row->block?->slug,
            'external_id' => $row->external_id,
            'parent_id' => $row->parent_id,
            'full_slug' => $row->full_slug,
            'content' => $this->getTransformedContent($resolved, $request),
            'relations' => $this->when(
                $this->shouldResolveRelations($request),
                fn (): array => $this->resolveRelations($request, $resolved),
            ),
            'language_iso' => $resolved->requestedLanguage,
            'translations' => $this->handleTranslations($resolved, $row),
            'published_at' => $row->published_at?->toIso8601String(),
            'first_published_at' => $row->first_published_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }

    protected function resolveContent(Request $request): ResolvedContent
    {
        if ($this->resource instanceof ResolvedContent) {
            return $this->resource;
        }

        $versionScope = $request->input('vid', 'published');

        /** @var Content $content */
        $content = $this->resource;
        $language = $content->language_iso;

        return app(ContentI18nResolver::class)->resolve(
            app('currentSpace'),
            $content,
            $language,
            $versionScope === 'draft' ? 'current' : $versionScope,
        );
    }

    protected function getTransformedContent(ResolvedContent $resolved, Request $request): array|\stdClass
    {
        $content = $resolved->effectiveContent;
        if (! $content) {
            return new \stdClass;
        }

        if (app('currentSpace')->settings->shouldFilterHiddenBlocks()) {
            $content = $this->removeHiddenBlocks($content);
        }

        $this->injectData($resolved, $content);

        $result = [
            ...$content,
            'block' => ($resolved->targetContent ?? $resolved->fallbackContent ?? $resolved->canonicalContent)
                ->loadMissing('block')
                ->block
                ?->slug,
        ];

        if ($request->has('take')) {
            $paths = ContentFieldSelector::parsePaths($request->input('take', ''));
            if (!empty($paths)) {
                $result = ContentFieldSelector::take($result, $paths);
            }
        } elseif ($request->has('except')) {
            $paths = ContentFieldSelector::parsePaths($request->input('except', ''));
            if (!empty($paths)) {
                $result = ContentFieldSelector::except($result, $paths);
            }
        }

        return $result;
    }

    protected function removeHiddenBlocks(array $content): array
    {
        foreach ($content as $key => $value) {
            if (! \is_array($value)) {
                continue;
            }

            $isBlocksArray = array_is_list($value) && \count($value) > 0 && isset($value[0]['block']);

            if ($isBlocksArray) {
                $content[$key] = array_values(
                    array_filter($value, fn (mixed $item) => ! (\is_array($item) && ($item['hidden'] ?? false) === true))
                );

                foreach ($content[$key] as $i => $item) {
                    if (\is_array($item)) {
                        $content[$key][$i] = $this->removeHiddenBlocks($item);
                    }
                }
            } else {
                $content[$key] = $this->removeHiddenBlocks($value);
            }
        }

        return $content;
    }

    protected function handleTranslations(ResolvedContent $resolved, Content $currentRow)
    {
        return SimpleContentResource::collection(
            $resolved->familyContents
                ->filter(fn (Content $content) => $content->published_at !== null && $content->id !== $currentRow->id)
                ->values()
        );
    }

    protected function injectData(ResolvedContent $resolved, array &$content)
    {
        $content = app(LinkHandler::class)->replaceContentLinks(
            $content,
            $resolved->effectiveLinks,
            $resolved->requestedLanguage,
            app('currentSpace')->settings->getDefaultLanguage(),
        );
        $assetContext = $resolved->targetContent ?? $resolved->fallbackContent ?? $resolved->canonicalContent;
        $content = app(AssetHandler::class)->replaceContentAssets($assetContext, $content, $resolved->effectiveAssets);
    }

    protected function shouldResolveRelations(Request $request): bool
    {
        return $this->relationResolutionDepth($request) < $this->relationResolutionMaxDepth($request);
    }

    protected function resolveRelations(Request $request, ResolvedContent $resolved): array
    {
        if ($resolved->effectiveRelations->isEmpty()) {
            return [];
        }

        $versionScope = $request->input('vid', 'published');
        $resolvedRelations = app(ContentI18nResolver::class)->resolveMany(
            app('currentSpace'),
            $resolved->effectiveRelations->map(
                fn (Content $content): array => [
                    'content' => $content,
                    'target_language' => $resolved->requestedLanguage,
                ]
            ),
            $versionScope === 'draft' ? 'current' : $versionScope,
        );

        $this->preloadResolvedRelationRows($resolvedRelations);

        $nestedRequest = clone $request;
        $nestedRequest->attributes->set(
            self::RELATION_RESOLUTION_DEPTH_ATTRIBUTE,
            $this->relationResolutionDepth($request) + 1,
        );

        return $resolvedRelations
            ->map(fn (ResolvedContent $relation): array => (new self($relation))->toArray($nestedRequest))
            ->values()
            ->all();
    }

    protected function preloadResolvedRelationRows(Collection $resolvedRelations): void
    {
        $rows = new EloquentCollection(
            $resolvedRelations
                ->map(fn (ResolvedContent $relation): ?Content => $relation->targetContent ?? $relation->fallbackContent ?? $relation->canonicalContent)
                ->filter()
                ->unique('id')
                ->values()
                ->all()
        );

        if ($rows->isNotEmpty()) {
            $rows->loadMissing('block');
        }
    }

    protected function relationResolutionMaxDepth(Request $request): int
    {
        return max((int) $request->attributes->get(self::RELATION_RESOLUTION_MAX_DEPTH_ATTRIBUTE, 0), 0);
    }

    protected function relationResolutionDepth(Request $request): int
    {
        return max((int) $request->attributes->get(self::RELATION_RESOLUTION_DEPTH_ATTRIBUTE, 0), 0);
    }
}
