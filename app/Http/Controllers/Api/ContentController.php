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
        $query = Content::filter(ContentFilter::fromRequest($request))
            ->select([
                'contents.*',
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

        return new ContentResourceCollection($query->paginate(min($request->per_page ?? 20, 500)));
    }

    public function show(Request $request, string $slug): ContentResource|Response
    {
        /** @var Space $space */
        $space = app('currentSpace');

        $language = $request->input('language') ?? $request->input('language_iso') ?? $space->settings->getDefaultLanguage();
        if (! \in_array($language, $space->settings->getEnabledLanguages())) {
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
        abort_if(! $candidate, 404);

        $versionScope = $request->input('vid', 'published');
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

        return new ContentResource($resolved);
    }

    protected function findFamilyCandidate(string $slug, string $language, Space $space): ?Content
    {
        $candidates = Content::query()
            ->where('full_slug', "/$slug")
            ->whereNull('deleted_at')
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
