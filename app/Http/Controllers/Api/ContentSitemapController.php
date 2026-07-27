<?php

namespace App\Http\Controllers\Api;

use App\Http\Filters\Api\ContentFilter;
use App\Http\Resources\Api\ContentSitemapResource;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Content\ContentI18nResolver;
use App\Services\Content\LinkHandler;
use App\Services\Content\ResolvedContent;
use App\Services\Content\SitemapContentService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * List the published tree's slugs and timestamps for sitemap generation,
 * honoring the space's sitemap-extraction settings (which block types are
 * exposed and where their SEO meta lives).
 */
class ContentSitemapController
{
    public function __construct(
        private readonly ContentI18nResolver $resolver,
        private readonly LinkHandler $linkHandler,
        private readonly SitemapContentService $sitemapContentService,
    ) {}

    public function __invoke(Request $request)
    {
        $space = app('currentSpace');
        $configuredTypes = $this->sitemapContentService->configuredMetaPathsByBlock($space);

        if ($configuredTypes->isEmpty()) {
            return ContentSitemapResource::collection($this->paginateCollection($request, collect()))->additional([
                'rv' => $space->rv,
            ]);
        }

        $blockIds = Block::query()->whereIn('slug', $configuredTypes->keys())->pluck('id');

        if ($blockIds->isEmpty()) {
            return ContentSitemapResource::collection($this->paginateCollection($request, collect()))->additional([
                'rv' => $space->rv,
            ]);
        }

        $query = Content::filter(ContentFilter::fromRequest($request))
            ->select([
                ...Content::deliveryColumns('contents.'),
                'content_versions.content',
                'content_versions.relation_ids',
                'content_versions.asset_ids',
                'content_versions.link_ids',
            ])
            ->with(['i18n_parent', 'i18n_children', 'i18n_siblings', 'block', 'relations', 'assets', 'links'])
            ->leftJoin('content_versions', 'contents.published_version_id', '=', 'content_versions.id')
            ->whereNotNull('contents.published_at')
            ->whereNotNull('contents.published_version_id')
            ->whereIn('contents.block_id', $blockIds);

        if (! $request->filled('sort')) {
            $query->orderBy('contents.full_slug');
        }

        if (! $this->hasExplicitLanguageFilter($request)) {
            $query
                ->where('contents.language_iso', $space->settings->getDefaultLanguage())
                ->whereNull('contents.i18n_parent_id');
        }

        $requestedLanguage =
            $request->input('language') ?? $request->input('language_iso') ?? $space->settings->getDefaultLanguage();

        $resolvedContents = $this->resolver->resolveMany(
            $space,
            $query->get()->map(fn (Content $content): array => [
                'content' => $content,
                'target_language' => $requestedLanguage,
            ]),
            'published',
        );

        $items = $resolvedContents
            ->map(fn (ResolvedContent $resolved): ?array => $this->transformResolvedContent($space, $resolved))
            ->filter()
            ->values();

        return ContentSitemapResource::collection($this->paginateCollection($request, $items))->additional([
            'rv' => $space->rv,
        ]);
    }

    private function hasExplicitLanguageFilter(Request $request): bool
    {
        return $request->filled('language') || $request->filled('language_iso');
    }

    private function transformResolvedContent($space, ResolvedContent $resolved): ?array
    {
        $row = $resolved->targetContent ?? $resolved->fallbackContent ?? $resolved->canonicalContent;

        $effectiveContent = $this->linkHandler->replaceContentLinks(
            $resolved->effectiveContent,
            $resolved->effectiveLinks,
        );
        $meta = $this->sitemapContentService->extractNormalizedMeta($space, $resolved, $effectiveContent);

        if (! $this->sitemapContentService->isIndexable($meta)) {
            return null;
        }

        return [
            'id' => $row->getRouteKey(),
            'name' => $row->name,
            'full_slug' => $row->full_slug,
            'language_iso' => $row->language_iso,
            'meta' => $meta,
            'published_at' => $row->published_at?->toIso8601String(),
        ];
    }

    private function paginateCollection(Request $request, Collection $items): LengthAwarePaginator
    {
        $perPage = min(max((int) $request->input('per_page', 20), 1), 1000);
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator($items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page, [
            'path' => $request->url(),
            'pageName' => 'page',
            'query' => $request->query(),
        ]);
    }
}
