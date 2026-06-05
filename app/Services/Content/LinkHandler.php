<?php

namespace App\Services\Content;

use App\Models\Space\Content;
use Illuminate\Support\Collection;

class LinkHandler
{
    use ContentExtractor;
    use ContentReplacer;

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

    public function replaceContentLinks(
        array $data,
        Collection $links,
        ?string $languageIso = null,
        ?string $defaultLanguageIso = null,
    ): array {
        $resolvedLinks = $this->resolveLocalizedLinks($links, $languageIso, $defaultLanguageIso);

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

        $familiesByCanonicalId = Content::query()
            ->where(function ($query) use ($canonicalIds): void {
                $query->whereIn('id', $canonicalIds)
                    ->orWhereIn('i18n_parent_id', $canonicalIds);
            })
            ->whereNull('deleted_at')
            ->get()
            ->groupBy(fn (Content $content): string => $content->i18n_parent_id ?: $content->id);

        return $linksById->mapWithKeys(function (Content $link) use (
            $familiesByCanonicalId,
            $languageIso,
            $defaultLanguageIso,
        ): array {
            $canonicalId = $link->i18n_parent_id ?: $link->id;
            $family = $familiesByCanonicalId->get($canonicalId, collect([$link]));
            $resolvedLink = $family->firstWhere('language_iso', $languageIso)
                ?? $family->firstWhere('language_iso', $defaultLanguageIso)
                ?? $link;

            return [$link->id => $resolvedLink];
        });
    }
}
