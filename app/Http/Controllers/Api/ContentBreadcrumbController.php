<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesDeliveryContent;
use App\Http\Resources\Api\ContentBreadcrumbCollection;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\BreadcrumbLevel;
use App\Services\Content\ContentBreadcrumbService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The ancestor trail of a content entry, from the tree root down to the entry
 * itself, resolved for one language.
 *
 * Every level is picked from its own i18n family along the requested language's
 * fallback chain, so a trail is never a mix of translated and untranslated
 * labels without saying which is which (`is_fallback`). `full_slug` is the
 * stored, locale-neutral path; `path` is the delivery URL with the requested
 * language's locale segment applied.
 *
 * Ancestors the published scope may not show are omitted rather than blanked:
 * an entry that was never published must not leak its name or slug through a
 * child's breadcrumb. `ancestors=all` opts back in — useful for entries that
 * are structural folders and are never published by design.
 */
class ContentBreadcrumbController
{
    use ResolvesDeliveryContent;

    public function __construct(private readonly ContentBreadcrumbService $breadcrumbService) {}

    /**
     * Get the breadcrumb trail of a content entry, addressed by its full slug
     * or by its id. `vid` selects the published or draft version, `language`
     * the language every level is resolved for.
     *
     * @param  string  $slug  Full slug of the entry (e.g. `products/shoes`) or its content id.
     */
    public function __invoke(Request $request, string $slug): ContentBreadcrumbCollection
    {
        /** @var Space $space */
        $space = app('currentSpace');

        // A breadcrumb has no meaningful notion of a single version id — every
        // level is a different entry — so only the two scopes are accepted here.
        $versionScope = $this->versionScope($request, allowVersionId: false);
        $language = $this->resolveLanguage($request, $space);

        $entry = $this->findEntry($slug, $language, $space);
        abort_if($entry === null, 404);

        // Same rule as `contents/{slug}`: an entry that was never published, or
        // was unpublished again, is not reachable in the published scope even
        // though the row is still there.
        if ($versionScope === 'published') {
            abort_if(! $entry->published_at || ! $entry->published_version_id, 404);
        }

        $levels = $this->breadcrumbService->build(
            space: $space,
            entry: $entry,
            language: $language,
            versionScope: $versionScope === 'draft' ? 'current' : $versionScope,
            includeUnpublishedAncestors: $request->input('ancestors') === 'all',
            includeSelf: $request->boolean('include_self', true),
            withTranslations: $request->boolean('translations'),
            withContent: $request->boolean('include_content'),
        );

        $this->applyCacheAttributes($request, $entry);

        return (new ContentBreadcrumbCollection($levels))
            ->additional([
                'meta' => $this->meta($space, $levels, $language),
                'rv' => $space->rv,
            ]);
    }

    /**
     * Slug first, id second.
     *
     * Ids are stored lowercased, so a 26-character slug is formally
     * indistinguishable from one. Resolving the path first means a real entry
     * always wins over a coincidence, and the id lookup only costs a query in
     * the case that actually uses it.
     */
    private function findEntry(string $slug, string $language, Space $space): ?Content
    {
        $entry = $this->findFamilyCandidate($slug, $language, $space);

        if ($entry !== null || ! Str::isUlid($slug)) {
            return $entry;
        }

        return Content::query()
            ->select(Content::deliveryColumns())
            ->find($slug);
    }

    /**
     * @param  Collection<int, BreadcrumbLevel>  $levels
     * @return array<string, mixed>
     */
    private function meta(Space $space, Collection $levels, string $language): array
    {
        $root = $levels->first(fn (BreadcrumbLevel $level): bool => $level->isRoot);
        $current = $levels->first(fn (BreadcrumbLevel $level): bool => $level->isCurrent);

        return [
            'language_iso' => $language,
            'fallback_language_iso' => $space->settings->getFallbackLanguage($language),
            'i18n_mode' => $space->settings->getI18nMode(),
            'levels' => $levels->count(),
            'root_id' => $root?->row->getRouteKey(),
            'current_id' => $current?->row->getRouteKey(),
        ];
    }
}
