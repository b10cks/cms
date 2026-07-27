<?php

namespace App\Services\Content;

use App\Models\Space\Content;
use Illuminate\Support\Collection;

class LinkHandler
{
    use ContentExtractor;
    use ContentReplacer;

    /**
     * Resolved i18n families, per publication scope, for this request.
     *
     * @var array<string, array<string, Collection<int, Content>>>
     */
    private array $familyCache = [];

    public function extractContentLinks(array $data): array
    {
        $regularLinks = $this->extractMatchingField($data, [
            'type' => 'internal',
        ], 'content');

        $richtextLinks = $this->extract(
            $data,
            fn (array $value): mixed => $this->matchesContentCriteria($value, ['type' => 'internalLink'])
                ? ($value['attrs']['content'] ?? null)
                : null,
        );

        return array_values(array_unique(array_merge($regularLinks, $richtextLinks)));
    }

    /**
     * @param  bool  $publishedOnly  Whether an unpublished translation may be
     *                               used to resolve a link. In the published
     *                               delivery scope it may not: the localized
     *                               row is fetched separately from the link
     *                               itself, so an unreleased translation would
     *                               otherwise contribute its name and slug.
     */
    public function replaceContentLinks(
        array $data,
        Collection $links,
        ?string $languageIso = null,
        ?string $defaultLanguageIso = null,
        bool $publishedOnly = true,
    ): array {
        $resolvedLinks = $this->resolveLocalizedLinks($links, $languageIso, $defaultLanguageIso, $publishedOnly);

        $data = $this->replaceMatching($data, [
            'type' => 'internal',
        ], function ($src) use ($resolvedLinks) {
            $link = $resolvedLinks->get($src['content'] ?? null);
            if ($link) {
                $src = [
                    'url' => $link->full_slug,
                    'title' => $link->name,
                ] + $src;
            }

            return $src;
        });

        return $this->replaceMatching($data, [
            'type' => 'internalLink',
        ], function ($src) use ($resolvedLinks) {
            $link = $resolvedLinks->get($src['attrs']['content'] ?? null);
            if ($link) {
                $src['attrs'] = array_merge(
                    ['url' => $link->full_slug, 'title' => $link->name],
                    $src['attrs'] ?? [],
                );
            }

            return $src;
        });
    }

    private function resolveLocalizedLinks(
        Collection $links,
        ?string $languageIso,
        ?string $defaultLanguageIso,
        bool $publishedOnly = true,
    ): Collection {
        $linksById = $links
            ->filter(fn (mixed $link): bool => $link instanceof Content)
            ->keyBy('id');

        if ($linksById->isEmpty() || $languageIso === null || $defaultLanguageIso === null) {
            return $linksById;
        }

        $canonicalIds = $linksById
            ->map(fn (Content $link): string => $link->i18n_parent_id ?: $link->id)
            ->unique()
            ->values();

        $familiesByCanonicalId = $this->familiesFor($canonicalIds, $publishedOnly);

        return $linksById->mapWithKeys(function (Content $link) use (
            $familiesByCanonicalId,
            $languageIso,
            $defaultLanguageIso,
        ): array {
            $canonicalId = $link->i18n_parent_id ?: $link->id;
            $family = $familiesByCanonicalId->get($canonicalId);

            if ($family === null || $family->isEmpty()) {
                // Nothing publishable in the family. Dropping the key leaves
                // the link node bare rather than falling back to the row we
                // were handed, which may itself be the unpublished one.
                return $publishedOnly ? [] : [$link->id => $link];
            }

            $resolvedLink = $family->firstWhere('language_iso', $languageIso)
                ?? $family->firstWhere('language_iso', $defaultLanguageIso)
                ?? $family->first();

            return [$link->id => $resolvedLink];
        });
    }

    /**
     * The i18n families for the given canonical ids, remembered for the rest
     * of the request.
     *
     * This runs once per rendered resource, so a listing — or a single entry
     * expanding its relations — used to issue one query per item even though
     * pages link to the same handful of targets over and over. Only ids not
     * already seen are fetched, and a canonical id with no publishable family
     * is remembered as empty so it is not asked for again.
     *
     * @param  Collection<int, string>  $canonicalIds
     * @return Collection<string, Collection<int, Content>>
     */
    private function familiesFor(Collection $canonicalIds, bool $publishedOnly): Collection
    {
        $cache = &$this->familyCache[$publishedOnly ? 'published' : 'any'];
        $cache ??= [];

        $missing = $canonicalIds->reject(fn (string $id): bool => \array_key_exists($id, $cache))->values();

        if ($missing->isNotEmpty()) {
            $fetched = Content::query()
                ->select(Content::deliveryColumns())
                ->where(function ($query) use ($missing): void {
                    $query->whereIn('id', $missing)
                        ->orWhereIn('i18n_parent_id', $missing);
                })
                ->whereNull('deleted_at')
                ->when($publishedOnly, fn ($query) => $query->published())
                ->get()
                ->groupBy(fn (Content $content): string => $content->i18n_parent_id ?: $content->id);

            foreach ($missing as $id) {
                $cache[$id] = $fetched->get($id, new Collection);
            }
        }

        return new Collection(
            $canonicalIds->mapWithKeys(fn (string $id): array => [$id => $cache[$id]])->all()
        );
    }
}
