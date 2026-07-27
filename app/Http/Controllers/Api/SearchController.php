<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SearchRequest;
use App\Http\Resources\Api\SearchResultCollection;
use App\Http\Resources\Api\SearchResultResource;
use App\Models\Management\Space;
use App\Services\Content\ContentI18nResolver;
use App\Services\Content\ResolvedContent;
use App\Services\Search\SearchService;

/**
 * Search published content entries in the current space.
 * @response SearchResultCollection<SearchResultResource>
 */
class SearchController extends Controller
{
    public function __construct(
        protected SearchService $searchService
    ) {
    }

    /**
     * @response SearchResultCollection<SearchResultResource>
     */
    public function __invoke(SearchRequest $request): SearchResultCollection
    {
        /** @var Space $space */
        $space = app('currentSpace');
        $validated = $request->validated();

        $query = $validated['q'];
        $limit = $validated['limit'] ?? 20;
        $offset = $validated['offset'] ?? 0;
        $language = $validated['language'] ?? $space->settings->getDefaultLanguage();
        if (!\in_array($language, $space->settings->getEnabledLanguages())) {
            $language = $space->settings->getDefaultLanguage();
        }

        $results = $this->searchService->search($space, $query, $language, $limit, $offset);

        // Resolve all hits in one batch instead of letting each resource run
        // its own family/version resolution — same pattern as the content index.
        $hits = collect($results['results']);
        $versionScope = $request->input('vid', 'published');
        $resources = app(ContentI18nResolver::class)
            ->resolveMany($space, $hits, $versionScope === 'draft' ? 'current' : $versionScope)
            ->map(fn (ResolvedContent $resolved, int $index): SearchResultResource => new SearchResultResource(
                $resolved,
                (float) data_get($hits[$index], 'relevance_score', 0),
            ));

        return (new SearchResultCollection($resources))->additional([
            'query' => $query,
            'total' => $results['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }
}
