<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesDeliveryContent;
use App\Http\Filters\Api\ContentFilter;
use App\Http\Resources\Api\ContentResource;
use App\Http\Resources\Api\ContentResourceCollection;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\Redirect;
use App\Services\Content\ContentI18nResolver;
use App\Services\Content\LocalizedContentSlugService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;

class ContentController
{
    use ResolvesDeliveryContent;

    protected LocalizedContentSlugService $slugService;

    public function __construct(LocalizedContentSlugService $slugService)
    {
        $this->slugService = $slugService;
    }

    /**
     * List content entries of the space, filtered, sorted, localized, and paginated.
     * Use `vid` to select the published or draft version, `language` plus
     * `include_fallback` for localized listings, and `parent_id`/`canonical_parent_id`
     * to fetch the children of a tree node.
     *
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
            ->with(['i18n_parent.block', 'i18n_children.block', 'i18n_siblings.block', 'block']);

        $vid = $this->versionScope($request, allowVersionId: false);
        if ($vid === 'published') {
            // Inner join plus the published_at check: a left join returned
            // never-published entries as rows with a null payload, which still
            // leaked the names and slugs of unreleased content.
            $query->join('content_versions', 'contents.published_version_id', '=', 'content_versions.id')
                ->whereNotNull('contents.published_at');
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
                fn (Content $content): array => [
                    'content' => $content,
                    'target_language' => $requestedLanguage,
                ]
            ),
            $versionScope,
        )->map(fn ($resolved) => new ContentResource($resolved));

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
     * @param  Builder<Content>  $query
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
        $settings = $parent?->settings;

        if ($settings === null) {
            return;
        }

        $contentField = $settings->getChildContentSortField();

        // `content.{field}` sorts on the joined version payload; the join only
        // exists for the published/draft scopes.
        if ($contentField !== null) {
            if (\in_array($request->input('vid', 'published'), ['published', 'draft'], true)) {
                $query
                    ->orderBy('content_versions.content->'.$contentField, $settings->getChildSortDirection())
                    ->orderBy('contents.name')
                    ->orderBy('contents.id');
            }

            return;
        }

        $column = $settings->getChildSortColumn();

        if ($column === null) {
            return;
        }

        $direction = $column === 'position' ? 'asc' : $settings->getChildSortDirection();

        $query
            ->orderBy('contents.'.$column, $direction)
            ->orderBy('contents.name')
            ->orderBy('contents.id');
    }

    /**
     * Get a single content entry by its full slug. `vid` selects the published
     * or draft version; with `language` set, localized slugs resolve and the
     * entry is returned in that language with fallback to the canonical values.
     */
    public function show(Request $request, string $slug): ContentResource|Response
    {
        /** @var Space $space */
        $space = app('currentSpace');
        $request->attributes->set(
            ContentResource::RELATION_RESOLUTION_MAX_DEPTH_ATTRIBUTE,
            $request->boolean('resolve_relations') ? 1 : 0,
        );

        $language = $this->resolveLanguage($request, $space);

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

        $candidate = $this->findFamilyCandidate($slug, $language, $space, [
            'block',
            'i18n_parent',
            'i18n_children',
            'i18n_siblings',
        ]);
        abort_if(! $candidate, 404);

        $versionScope = $this->versionScope($request);

        // An entry that was never published, or was unpublished again, must not
        // be reachable in the published scope even though the row still exists.
        if ($versionScope === 'published') {
            abort_if(! $candidate->published_at || ! $candidate->published_version_id, 404);
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
            ($resolved->effectiveMode === 'independent' && ! $resolved->targetContent) ||
            ($resolved->effectiveMode === 'overlay' && ! $resolved->targetContent && ! $resolved->fallbackContent)
        ) {
            abort(404);
        }

        $this->applyCacheAttributes($request, $resolved->targetContent ?? $resolved->fallbackContent ?? $resolved->canonicalContent);

        return new ContentResource($resolved);
    }

}
