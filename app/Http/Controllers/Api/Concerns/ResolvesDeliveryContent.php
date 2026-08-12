<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Middleware\CacheDataApi;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The lookup rules every delivery endpoint that addresses a single entry has to
 * agree on: which version scope was asked for, which language it is resolved in,
 * which row of an i18n family answers a path, and what caching the entry's own
 * settings ask for.
 *
 * These used to live twice — once on `contents/{slug}`, once on the breadcrumb —
 * and had already drifted apart (the breadcrumb copy had lost the soft-delete
 * guard). Sharing them is what keeps the two endpoints answering the same
 * question with the same answer.
 */
trait ResolvesDeliveryContent
{
    /**
     * The version scope a request asks for, rejecting anything else.
     *
     * `vid` used to be taken verbatim. On show, an unrecognized value fell
     * through to a lookup by version id and then quietly back to the published
     * version; on index it left the query selecting `content_versions` columns
     * with no join at all, which is a database error rather than a listing. Both
     * scopes are now stated explicitly, and only endpoints where a version id
     * identifies one row of one entry accept one.
     */
    protected function versionScope(Request $request, bool $allowVersionId = true): string
    {
        $message = $allowVersionId
            ? 'Parameter "vid" must be "published", "draft" or a version id.'
            : 'Parameter "vid" must be "published" or "draft".';

        $vid = $request->input('vid', 'published');

        if (! \is_string($vid) || $vid === '') {
            abort(422, $message);
        }

        if ($vid === 'published' || $vid === 'draft') {
            return $vid;
        }

        abort_unless($allowVersionId && Str::isUlid($vid), 422, $message);

        return $vid;
    }

    /**
     * The language a request is resolved in — the requested one when the space
     * has it enabled, its default otherwise. An unknown language must not be
     * able to produce an empty response for content that does exist.
     */
    protected function resolveLanguage(Request $request, Space $space): string
    {
        $language = $request->input('language')
            ?? $request->input('language_iso')
            ?? $space->settings->getDefaultLanguage();

        return \in_array($language, $space->settings->getEnabledLanguages(), true)
            ? $language
            : $space->settings->getDefaultLanguage();
    }

    /**
     * The row of an i18n family that answers a path.
     *
     * One `full_slug` can exist in several languages, and the requested one wins
     * before its fallback and the space default. Deleted rows never answer: the
     * entry is gone from delivery whether it is addressed directly or as a
     * breadcrumb level.
     *
     * @param  array<int, string>  $with  Relations to eager load on the candidates.
     */
    protected function findFamilyCandidate(string $slug, string $language, Space $space, array $with = []): ?Content
    {
        $candidates = Content::query()
            ->select(Content::deliveryColumns())
            ->where('full_slug', '/'.ltrim($slug, '/'))
            ->whereNull('deleted_at')
            ->with($with)
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

    /**
     * Hand the entry's own cache settings to the delivery cache middleware.
     */
    protected function applyCacheAttributes(Request $request, ?Content $row): void
    {
        $settings = $row?->settings;
        if (! $settings) {
            return;
        }

        $ttl = $settings->cacheTtl();
        if ($ttl !== null) {
            $request->attributes->set(CacheDataApi::TTL_ATTRIBUTE, $ttl);
        }

        $tags = $settings->cacheTags();
        if ($tags !== []) {
            $request->attributes->set(CacheDataApi::TAGS_ATTRIBUTE, $tags);
        }
    }
}
