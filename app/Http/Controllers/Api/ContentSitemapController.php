<?php

namespace App\Http\Controllers\Api;

use App\Http\Filters\Api\ContentFilter;
use App\Http\Resources\Api\ContentSitemapResource;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Content\LinkHandler;
use App\Services\Content\SitemapContentService;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * List the published tree's slugs and timestamps for sitemap generation,
 * honoring the space's sitemap-extraction settings (which block types are
 * exposed and where their SEO meta lives).
 *
 * Pagination, `noindex` filtering and meta extraction all happen in the
 * database: only the requested page and the configured meta sub-object ever
 * reach PHP. A row whose effective robots value contains `noindex` or `none`
 * is excluded. In overlay i18n mode a translation's meta falls back per key
 * to the canonical row's published meta.
 */
class ContentSitemapController
{
    public function __construct(
        private readonly LinkHandler $linkHandler,
        private readonly SitemapContentService $sitemapContentService,
    ) {}

    /**
     * The default sitemap, covering every block type configured in
     * `settings.sitemap.types`.
     */
    public function __invoke(Request $request)
    {
        $space = app('currentSpace');

        return $this->sitemapResponse($request, $space, $this->sitemapContentService->configuredMetaPathsByBlock($space));
    }

    /**
     * A named sitemap from `settings.sitemaps`, e.g. one sitemap for pages
     * and a separate one for news. Each definition carries its own
     * block-to-meta-path mappings; unknown slugs return 404.
     */
    public function show(Request $request, string $sitemap)
    {
        $space = app('currentSpace');
        $metaPathsByBlock = $this->sitemapContentService->metaPathsForSitemap($space, $sitemap);

        abort_if($metaPathsByBlock === null, 404);

        return $this->sitemapResponse($request, $space, $metaPathsByBlock);
    }

    /**
     * @param  Collection<string, string>  $metaPathsByBlock
     */
    private function sitemapResponse(Request $request, Space $space, Collection $metaPathsByBlock)
    {
        $perPage = min(max((int) $request->input('per_page', 20), 1), 1000);

        if ($metaPathsByBlock->isEmpty()) {
            return $this->emptyResponse($request, $space, $perPage);
        }

        /** @var Collection<string, string> $blocks id => slug */
        $blocks = Block::query()->whereIn('slug', $metaPathsByBlock->keys())->pluck('slug', 'id');

        if ($blocks->isEmpty()) {
            return $this->emptyResponse($request, $space, $perPage);
        }

        $withBaseFallback = $space->settings->getI18nMode() === 'overlay'
            && $this->hasExplicitLanguageFilter($request);

        [$robotsSql, $robotsBindings] = $this->metaFieldExpression($blocks, $metaPathsByBlock, 'robots', $withBaseFallback);
        [$canonicalSql, $canonicalBindings] = $this->metaFieldExpression($blocks, $metaPathsByBlock, 'canonical', $withBaseFallback);

        $query = Content::filter(ContentFilter::fromRequest($request))
            ->select([
                'contents.id',
                'contents.name',
                'contents.full_slug',
                'contents.language_iso',
                'contents.published_at',
            ])
            ->selectRaw("{$robotsSql} as sitemap_robots", $robotsBindings)
            ->selectRaw("{$canonicalSql} as sitemap_canonical", $canonicalBindings)
            ->leftJoin('content_versions', 'contents.published_version_id', '=', 'content_versions.id')
            ->whereNotNull('contents.published_at')
            ->whereNotNull('contents.published_version_id')
            ->whereIn('contents.block_id', $blocks->keys());

        if ($withBaseFallback) {
            $query
                ->leftJoin('contents as i18n_base', function (JoinClause $join): void {
                    $join->on('contents.i18n_parent_id', '=', 'i18n_base.id')
                        ->whereNull('i18n_base.deleted_at');
                })
                ->leftJoin(
                    'content_versions as i18n_base_versions',
                    'i18n_base.published_version_id',
                    '=',
                    'i18n_base_versions.id',
                );
        }

        $query->whereRaw(
            "({$robotsSql} IS NULL OR ({$robotsSql} NOT LIKE '%noindex%' AND {$robotsSql} NOT LIKE '%none%'))",
            [...$robotsBindings, ...$robotsBindings, ...$robotsBindings],
        );

        if (! $request->filled('sort')) {
            $query->orderBy('contents.full_slug');
        }

        if (! $this->hasExplicitLanguageFilter($request)) {
            $query
                ->where('contents.language_iso', $space->settings->getDefaultLanguage())
                ->whereNull('contents.i18n_parent_id');
        }

        $paginator = $query->paginate($perPage)->appends($request->query());
        $paginator->setCollection($this->transformRows($paginator->getCollection()));

        return ContentSitemapResource::collection($paginator)->additional([
            'rv' => $space->rv,
        ]);
    }

    private function hasExplicitLanguageFilter(Request $request): bool
    {
        return $request->filled('language') || $request->filled('language_iso');
    }

    /**
     * SQL expression extracting one meta field (e.g. `robots`) from the
     * published version's content at the block's configured meta path. With
     * base fallback, a translation's missing value coalesces to the canonical
     * row's published value — the per-key overlay merge, in SQL.
     *
     * @param  Collection<string, string>  $blocks  id => slug
     * @param  Collection<string, string>  $metaPathsByBlock  slug => dot path
     * @return array{0: string, 1: array<int, string>}
     */
    private function metaFieldExpression(
        Collection $blocks,
        Collection $metaPathsByBlock,
        string $field,
        bool $withBaseFallback,
    ): array {
        [$ownSql, $ownBindings] = $this->jsonExtractCase('content_versions', $blocks, $metaPathsByBlock, $field);

        if (! $withBaseFallback) {
            return [$ownSql, $ownBindings];
        }

        [$baseSql, $baseBindings] = $this->jsonExtractCase('i18n_base_versions', $blocks, $metaPathsByBlock, $field);

        return ["COALESCE({$ownSql}, {$baseSql})", [...$ownBindings, ...$baseBindings]];
    }

    /**
     * @param  Collection<string, string>  $blocks  id => slug
     * @param  Collection<string, string>  $metaPathsByBlock  slug => dot path
     * @return array{0: string, 1: array<int, string>}
     */
    private function jsonExtractCase(
        string $table,
        Collection $blocks,
        Collection $metaPathsByBlock,
        string $field,
    ): array {
        $clauses = [];
        $bindings = [];

        foreach ($blocks as $blockId => $blockSlug) {
            $clauses[] = "WHEN ? THEN json_extract({$table}.content, ?)";
            $bindings[] = $blockId;
            $bindings[] = $this->jsonPath($metaPathsByBlock->get($blockSlug).'.'.$field);
        }

        return ['(CASE contents.block_id '.implode(' ', $clauses).' END)', $bindings];
    }

    /**
     * Quoted JSON path (`meta.robots` -> `$."meta"."robots"`), understood by
     * both MySQL and SQLite.
     */
    private function jsonPath(string $dotPath): string
    {
        $segments = array_filter(explode('.', $dotPath), fn (string $segment): bool => $segment !== '');

        return '$'.implode('', array_map(
            fn (string $segment): string => '."'.str_replace(['\\', '"'], '', $segment).'"',
            $segments,
        ));
    }

    /**
     * @param  Collection<int, Content>  $rows
     */
    private function transformRows(Collection $rows): Collection
    {
        $canonicals = $rows->mapWithKeys(fn (Content $row): array => [
            $row->id => $this->decodeJsonValue($row->getAttribute('sitemap_canonical')),
        ]);
        $linkTargets = $this->publishedLinkTargets($canonicals);

        return $rows->map(function (Content $row) use ($canonicals, $linkTargets): array {
            $canonical = $canonicals[$row->id];

            if (is_array($canonical)) {
                $canonical = $this->linkHandler->replaceContentLinks(['canonical' => $canonical], $linkTargets)['canonical'];
            }

            return [
                'id' => $row->getRouteKey(),
                'name' => $row->name,
                'full_slug' => $row->full_slug,
                'language_iso' => $row->language_iso,
                'meta' => [
                    'robots' => $this->sitemapContentService->normalizeRobots(
                        $this->decodeJsonValue($row->getAttribute('sitemap_robots')),
                    ),
                    'canonical' => $this->sitemapContentService->normalizeCanonical($canonical),
                ],
                'published_at' => $row->published_at?->toIso8601String(),
            ];
        })->values();
    }

    /**
     * The published contents referenced by internal canonical links on the
     * current page, keyed for LinkHandler replacement.
     *
     * @param  Collection<string, mixed>  $canonicals
     * @return Collection<int, Content>
     */
    private function publishedLinkTargets(Collection $canonicals): Collection
    {
        $ids = $this->linkHandler->extractContentLinks([
            'links' => $canonicals->filter(fn (mixed $canonical): bool => is_array($canonical))->values()->all(),
        ]);

        if ($ids === []) {
            return new Collection;
        }

        return Content::query()
            ->select(Content::deliveryColumns())
            ->whereIn('id', $ids)
            ->published()
            ->get();
    }

    /**
     * JSON scalars come back quoted from MySQL's json_extract and bare from
     * SQLite's; objects come back as JSON text from both.
     */
    private function decodeJsonValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function emptyResponse(Request $request, Space $space, int $perPage)
    {
        return ContentSitemapResource::collection(new LengthAwarePaginator(
            [],
            0,
            $perPage,
            LengthAwarePaginator::resolveCurrentPage(),
            [
                'path' => $request->url(),
                'pageName' => 'page',
                'query' => $request->query(),
            ],
        ))->additional([
            'rv' => $space->rv,
        ]);
    }
}
