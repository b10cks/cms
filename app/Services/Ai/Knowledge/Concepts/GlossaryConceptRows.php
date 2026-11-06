<?php

namespace App\Services\Ai\Knowledge\Concepts;

use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossaryConcept;
use App\Models\Management\AiGlossaryTerm;
use App\Models\Management\Space;
use App\Services\Ai\Knowledge\GlossaryMatcher;
use Collator;
use Generator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Reads a glossary as rows.
 *
 * Search reads across a row's languages and Avoid and Also written as are JSON in text
 * columns, which neither SQLite nor MySQL search reliably across escaped Unicode.
 * So one lean scan of the glossary's columns serves search, sort and facets
 * together, and only the requested page loads full models.
 *
 * @phpstan-type Filters array{search?: string|null, missing?: string|null}
 * @phpstan-type Scanned array{key: string, locales: array<string, true>, sort: string|null, sort_rank: int, any: string, found: bool, relevance: int}
 */
final class GlossaryConceptRows
{
    private const NOT_FOUND = 9;

    /**
     * @param  Filters  $filters
     * @return array{rows: LengthAwarePaginator<int, ConceptRow>, meta: array<string, mixed>}
     */
    public function list(AiGlossary $glossary, ?Space $space, array $filters, int $perPage, int $page, bool $readOnly): array
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $terms = $this->scanQuery($glossary, $search !== '');

        $locales = new GlossaryLocales($space, $terms->pluck('locale')->unique()->values()->all());
        $rows = $this->scan($terms, $locales->default, $search);

        $meta = [
            'read_only' => $readOnly,
            'default_locale' => $locales->default !== null ? $locales->code($locales->default) : null,
            'available_locales' => $locales->available(),
            'facets' => $this->facets($rows, $locales),
        ];

        $matching = array_filter($rows, fn (array $row) => $this->matches($row, $filters, $search, $locales));
        $matching = $this->sorted($matching, $locales->default, $search !== '');
        $slice = \array_slice($matching, ($page - 1) * $perPage, $perPage);

        return [
            'rows' => new LengthAwarePaginator($this->hydrate($slice, $locales), \count($matching), $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]),
            'meta' => $meta,
        ];
    }

    /**
     * Every row in list order, for an export: one scan, then full models a chunk at a time.
     *
     * @return Generator<int, ConceptRow>
     */
    public function all(AiGlossary $glossary, ?Space $space, int $chunk = 500): Generator
    {
        $terms = $this->scanQuery($glossary, false);
        $locales = new GlossaryLocales($space, $terms->pluck('locale')->unique()->values()->all());
        $sorted = $this->sorted($this->scan($terms, $locales->default, ''), $locales->default, false);
        unset($terms);

        foreach (array_chunk($sorted, $chunk) as $rows) {
            yield from $this->hydrate($rows, $locales);
        }
    }

    /** One row by its id, or null. */
    public function row(AiGlossary $glossary, ?Space $space, string $rowId): ?ConceptRow
    {
        $concept = AiGlossaryConcept::query()->where('glossary_id', $glossary->id)->whereKey($rowId)->with('terms')->first();

        if ($concept === null) {
            return null;
        }

        $stored = AiGlossaryTerm::query()->inGlossary($glossary->id)->toBase()->distinct()->pluck('locale')->all();

        return ConceptRow::of($concept, new GlossaryLocales($space, $stored));
    }

    /**
     * @return Collection<int, object>
     */
    private function scanQuery(AiGlossary $glossary, bool $searching): Collection
    {
        return AiGlossaryTerm::query()->toBase()
            ->join('ai_glossary_concepts', 'ai_glossary_concepts.id', '=', 'ai_glossary_terms.concept_id')
            ->where('ai_glossary_concepts.glossary_id', $glossary->id)
            ->get(['concept_id', 'locale', 'preferred', 'forbidden', ...($searching ? ['aliases', 'note'] : [])]);
    }

    /**
     * @param  iterable<object>  $terms
     * @return array<string, Scanned>
     */
    private function scan(iterable $terms, ?string $default, string $search): array
    {
        $default = $default !== null ? GlossaryMatcher::normalizeLocale($default) : null;
        $defaultBase = $default !== null ? explode('-', $default)[0] : null;
        $rows = [];

        foreach ($terms as $term) {
            $key = $term->concept_id;
            $forbidden = json_decode((string) $term->forbidden, true) ?: [];
            $folded = mb_strtolower((string) ($term->preferred ?? $forbidden[0] ?? ''));
            $row = $rows[$key] ?? ['key' => $key, 'locales' => [], 'sort' => null, 'sort_rank' => 0, 'any' => $folded, 'found' => false, 'relevance' => self::NOT_FOUND];

            $row['locales'][$term->locale] = true;
            $row['any'] = min($row['any'], $folded);

            $rank = $term->locale === $default ? 2 : ($term->locale === $defaultBase ? 1 : 0);

            if ($rank > $row['sort_rank']) {
                $row['sort'] = $folded;
                $row['sort_rank'] = $rank;
            }

            $relevance = $search !== '' ? $this->relevance($term, $forbidden, $folded, $search) : self::NOT_FOUND;
            $row['found'] = $row['found'] || $relevance !== self::NOT_FOUND;
            $row['relevance'] = min($row['relevance'], $relevance);
            $rows[$key] = $row;
        }

        return $rows;
    }

    /**
     * How well a search fits one term: 0 its wording is the search, 1 it starts with
     * it, 2 it contains it, 3 only Avoid, Also written as or the note does, `NOT_FOUND`
     * otherwise. A row takes its best term, in any language.
     *
     * @param  array<int, mixed>  $forbidden
     */
    private function relevance(object $term, array $forbidden, string $folded, string $search): int
    {
        if ($folded === $search) {
            return 0;
        }

        if (str_starts_with($folded, $search)) {
            return 1;
        }

        if (str_contains($folded, $search)) {
            return 2;
        }

        foreach ([json_decode((string) $term->aliases, true) ?: [], $forbidden] as $list) {
            foreach ($list as $wording) {
                if (\is_string($wording) && str_contains(mb_strtolower($wording), $search)) {
                    return 3;
                }
            }
        }

        return str_contains(mb_strtolower((string) $term->note), $search) ? 3 : self::NOT_FOUND;
    }

    /**
     * Whole-glossary counts, each row once per value it holds in any language.
     *
     * @param  array<string, Scanned>  $rows
     * @return array{total: int, locales: list<array{value: string, count: int}>, missing: list<array{value: string, count: int}>}
     */
    private function facets(array $rows, GlossaryLocales $locales): array
    {
        $used = [];
        $missing = array_fill_keys(array_column(array_filter($locales->available(), static fn (array $locale) => ! $locale['unused']), 'code'), 0);

        foreach ($rows as $row) {
            foreach ($row['locales'] as $locale => $_) {
                $code = $locales->code($locale);
                $used[$code] = ($used[$code] ?? 0) + 1;
            }

            foreach ($missing as $code => $count) {
                if (! $locales->covers($row['locales'], (string) $code)) {
                    $missing[$code] = $count + 1;
                }
            }
        }

        return [
            'total' => \count($rows),
            'locales' => $this->facetList($used),
            'missing' => $this->facetList($missing),
        ];
    }

    /**
     * @param  array<array-key, int>  $counts
     * @return list<array{value: string, count: int}>
     */
    private function facetList(array $counts): array
    {
        ksort($counts, SORT_STRING);
        $list = [];

        foreach ($counts as $value => $count) {
            $list[] = ['value' => (string) $value, 'count' => $count];
        }

        return $list;
    }

    /**
     * @param  Scanned  $row
     * @param  Filters  $filters
     */
    private function matches(array $row, array $filters, string $search, GlossaryLocales $locales): bool
    {
        return ($search === '' || $row['found'])
            && (($filters['missing'] ?? null) === null || ! $locales->covers($row['locales'], $filters['missing']));
    }

    /**
     * By the default language's term, in that language's alphabet. A row without one
     * follows, by its first term. A search puts the closest fit first.
     *
     * @param  array<string, Scanned>  $rows
     * @return list<Scanned>
     */
    private function sorted(array $rows, ?string $default, bool $searching): array
    {
        $collator = new Collator(str_replace('-', '_', $default ?? 'en'));
        $keyed = array_map(static fn (array $row) => [
            $searching ? $row['relevance'] : 0,
            $row['sort'] === null ? 1 : 0,
            $collator->getSortKey($row['sort'] ?? $row['any']),
            $row['key'],
            $row,
        ], array_values($rows));

        usort($keyed, static fn (array $a, array $b) => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]) ?: (strcmp($a[2], $b[2]) ?: strcmp($a[3], $b[3])));

        return array_column($keyed, 4);
    }

    /**
     * @param  list<Scanned>  $rows
     * @return list<ConceptRow>
     */
    private function hydrate(array $rows, GlossaryLocales $locales): array
    {
        $concepts = AiGlossaryConcept::query()->whereIn('id', array_column($rows, 'key'))->with('terms')->get()->keyBy('id');

        return array_map(static fn (array $row) => ConceptRow::of($concepts[$row['key']], $locales), $rows);
    }
}
