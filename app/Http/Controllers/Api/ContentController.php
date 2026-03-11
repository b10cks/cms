<?php

namespace App\Http\Controllers\Api;

use App\Http\Filters\Api\ContentFilter;
use App\Http\Resources\Api\ContentResource;
use App\Http\Resources\Api\ContentResourceCollection;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\Redirect;
use App\Services\Content\LocalizedContentSlugService;
use Illuminate\Http\Request;

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
        $data = Content::filter(ContentFilter::fromRequest($request))
            ->leftJoin('content_versions', 'contents.published_version_id', '=', 'content_versions.id')
            ->select([
                'contents.*',
                'content_versions.content',
                'content_versions.relation_ids',
                'content_versions.asset_ids',
                'content_versions.link_ids'
            ])
            ->with(['i18n_parent', 'i18n_children', 'i18n_siblings', 'block', 'relations', 'assets', 'links'])
            ->paginate(min($request->per_page ?? 20, 500));

        return new ContentResourceCollection($data);
    }

    public function show(Request $request, string $slug): ContentResource|\Illuminate\Http\Response
    {
        /** @var Space $space */
        $space = app('currentSpace');

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

        $vid = $request->input('vid', 'published');
        $content = $this->fetchContent($slug, $language, $vid);

        if (!$content && $language !== $space->settings->getDefaultLanguage()) {
            $content = $this->fetchContent($slug, $space->settings->getDefaultLanguage(), $vid);
        }

        return new ContentResource($content ?? $this->fetchContent($slug, $language, $vid, true));
    }

    protected function fetchContent(string $slug, string $language, string $vid, bool $fail = false): ?Content
    {
        $query = Content::where('full_slug', "/$slug")
            ->where('language_iso', $language)
            ->with(['i18n_parent', 'i18n_children', 'i18n_siblings', 'block', 'relations', 'assets', 'links'])
            ->select([
                'contents.*',
                'content_versions.content',
                'content_versions.relation_ids',
                'content_versions.asset_ids',
                'content_versions.link_ids'
            ]);

        if ($vid === 'published') {
            $query->leftJoin('content_versions', 'contents.published_version_id', '=', 'content_versions.id');
        } elseif ($vid === 'draft') {
            $query->leftJoin('content_versions', 'contents.current_version_id', '=', 'content_versions.id');
        } else {
            $query->leftJoin('content_versions', 'contents.id', '=', 'content_versions.content_id')
                ->where('content_versions.id', $vid);
        }

        return $fail ? $query->firstOrFail() : $query->first();
    }
}
