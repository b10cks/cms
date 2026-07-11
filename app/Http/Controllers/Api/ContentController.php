<?php

namespace App\Http\Controllers\Api;

use App\Http\Filters\Api\ContentFilter;
use App\Http\Resources\Api\ContentResource;
use App\Http\Resources\Api\ContentResourceCollection;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\Redirect;
use App\Services\Content\ContentI18nResolver;
use App\Services\Content\LocalizedContentSlugService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;

class ContentController
{
    protected LocalizedContentSlugService $slugService;

    public function __construct(LocalizedContentSlugService $slugService)
    {
        $this->slugService = $slugService;
    }

    /**
     * @response ContentResourceCollection<LengthAwarePaginator<ContentResource>>
     */
    public function index(Request $request): ContentResourceCollection
    {
        abort_if(
            $request->has('take') && $request->has('except'),
            422,
            'Parameters "take" and "except" are mutually exclusive.',
        );

        $query = Content::filter(ContentFilter::fromRequest($request))
            ->select([
                ...Content::deliveryColumns('contents.'),
                'content_versions.content',
                'content_versions.relation_ids',
                'content_versions.asset_ids',
                'content_versions.link_ids',
            ])
            ->with(['i18n_parent', 'i18n_children', 'i18n_siblings', 'block', 'relations', 'assets', 'links']);

        $vid = $request->input('vid', 'published');
        if ($vid === 'published') {
            $query->leftJoin('content_versions', 'contents.published_version_id', '=', 'content_versions.id');
        } elseif ($vid === 'draft') {
            $query->leftJoin('content_versions', 'contents.current_version_id', '=', 'content_versions.id');
        }

        $this->applyConfiguredChildOrdering($query, $request);

        $paginator = $query->paginate(min($request->per_page ?? 20, 500));
        $resolver = app(ContentI18nResolver::class);
        $space = app('currentSpace');
        $versionScope = $vid === 'draft' ? 'current' : $vid;

        $requestedLanguage = $request->input('language') ?? $request->input('language_iso') ?? $space->settings->getDefaultLanguage();

        $resolvedItems = $resolver->resolveMany(
            $space,
            $paginator->getCollection()->map(
                fn(Content $content): array => [
                    'content' => $content,
                    'target_language' => $requestedLanguage,
                ]
            ),
            $versionScope,
        )->map(fn($resolved) => new ContentResource($resolved));

        $resolvedPaginator = new LengthAwarePaginator(
            $resolvedItems,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            [
                'path' => $paginator->path(),
                'pageName' => $paginator->getPageName(),
                'query' => request()->query(),
            ],
        );

        return new ContentResourceCollection($resolvedPaginator);
    }

    /**
     * When a request lists the children of a single parent without an explicit
     * `sort`, order them by the sorting configured on that parent (e.g. a news
     * folder sorted by `published_at`). Requests without such a configuration
     * keep their previous (unspecified) ordering.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Content>  $query
     */
    protected function applyConfiguredChildOrdering($query, Request $request): void
    {
        if ($request->filled('sort')) {
            return;
        }

        $parentId = $request->input('parent_id') ?? $request->input('canonical_parent_id');

        // Only plain single-ID values; operator syntax like `in:a,b` targets
        // multiple parents where a per-folder ordering is ambiguous.
        if (! \is_string($parentId) || $parentId === '' || str_contains($parentId, ':')) {
            return;
        }

        $parent = Content::query()->select(['id', 'settings'])->find($parentId);
        $column = $parent?->settings?->getChildSortColumn();

        if ($column === null) {
            return;
        }

        $direction = $column === 'position' ? 'asc' : $parent->settings->getChildSortDirection();

        $query
            ->orderBy('contents.'.$column, $direction)
            ->orderBy('contents.name')
            ->orderBy('contents.id');
    }

    public function show(Request $request, string $slug): ContentResource|Response
    {
        /** @var Space $space */
        $space = app('currentSpace');
        $request->attributes->set(
            ContentResource::RELATION_RESOLUTION_MAX_DEPTH_ATTRIBUTE,
            $request->boolean('resolve_relations') ? 1 : 0,
        );

        $language = $request->input('language') ?? $request->input('language_iso') ?? $space->settings->getDefaultLanguage();
        if (!\in_array($language, $space->settings->getEnabledLanguages())) {
            $language = $space->settings->getDefaultLanguage();
        }

        $redirect = Redirect::where('source', "/$language/$slug")
            ->first();

        if ($redirect) {
            $redirect->trackUsage();

            return response([
                'redirect' => true,
                'to' => $redirect->target,
                'status_code' => $redirect->status_code,
            ], $redirect->status_code);
        }

        $candidate = $this->findFamilyCandidate($slug, $language, $space);
        abort_if(!$candidate, 404);

        $versionScope = $request->input('vid', 'published');

        if ($versionScope === 'published') {
            $candidate->loadMissing([
                'published_version.assets',
                'published_version.links',
                'published_version.relations',
            ]);
        } elseif ($versionScope === 'draft') {
            $candidate->loadMissing([
                'current_version.assets',
                'current_version.links',
                'current_version.relations',
            ]);
        }

        $candidate->loadMissing([
            'block',
            'i18n_parent',
            'i18n_children',
            'i18n_siblings',
        ]);
        $resolved = app(ContentI18nResolver::class)->resolve(
            $space,
            $candidate,
            $language,
            $versionScope === 'draft' ? 'current' : $versionScope,
        );

        if (
            ($resolved->effectiveMode === 'independent' && !$resolved->targetContent) ||
            ($resolved->effectiveMode === 'overlay' && !$resolved->targetContent && !$resolved->fallbackContent)
        ) {
            abort(404);
        }

        return new ContentResource($resolved);
    }

    protected function findFamilyCandidate(string $slug, string $language, Space $space): ?Content
    {
        $candidates = Content::query()
            ->select(Content::deliveryColumns())
            ->where('full_slug', "/$slug")
            ->whereNull('deleted_at')
            ->with([
                'block',
                'i18n_parent',
                'i18n_children',
                'i18n_siblings',
            ])
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $priority = array_values(array_unique(array_filter([
            $language,
            $space->settings->getFallbackLanguage($language),
            $space->settings->getDefaultLanguage(),
        ])));

        foreach ($priority as $priorityLanguage) {
            $match = $candidates->firstWhere('language_iso', $priorityLanguage);
            if ($match) {
                return $match;
            }
        }

        return $candidates->first();
    }
}
