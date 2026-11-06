<?php

namespace App\Services\Ai\Knowledge;

/**
 * One row of a policy frozen before rows became concepts (snapshot version 1, and the
 * `terms` of a run's setup). What it was pinned under still holds, so work that started
 * then answers the same now. The old rules live here and nowhere else:
 *
 * - Case is the row's own `case_sensitive`, apart from Keep exact.
 * - Each language was its own row. A row without a concept also applies to a text in a
 *   sibling region, at the lowest rank.
 * - Avoid wording of a row without a concept counts in every output language, except a
 *   ban, which names wording of its own language. A linked row's counts in its language.
 * - `translations` are one way: they say what to write in a target, and do not make the
 *   target wording findable in a source.
 *
 * Current rows and new snapshots never reach this class.
 *
 * @phpstan-type Sibling array{id: string|null, locale: string, forbidden: list<string>, note: string|null}
 */
final readonly class FrozenGlossaryRow extends GlossaryConcept
{
    /**
     * @param  array<string, mixed>  $row  the frozen shape
     * @param  list<Sibling>  $siblings  the rows of its concept, itself included
     */
    private function __construct(private array $row, private array $siblings)
    {
        parent::__construct(
            [new GlossaryWording(
                GlossaryMatcher::normalizeLocale((string) $row['source_locale']),
                self::banned($row) ? null : (string) $row['term'],
                $row['forbidden'] ?? [],
                $row['source_aliases'] ?? [],
                (string) $row['term'],
            )],
            (bool) ($row['do_not_translate'] ?? false),
            $row['note'] ?? null,
            $row['concept_id'] ?? $row['id'] ?? null,
            $row['glossary_id'] ?? null,
            (bool) ($row['case_sensitive'] ?? false),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<self>
     */
    public static function list(array $rows): array
    {
        $byConcept = [];

        foreach ($rows as $row) {
            if (isset($row['concept_id'])) {
                $byConcept[$row['concept_id']][] = [
                    'id' => $row['id'] ?? null,
                    'locale' => GlossaryMatcher::normalizeLocale((string) $row['source_locale']),
                    'forbidden' => $row['forbidden'] ?? [],
                    'note' => $row['note'] ?? null,
                ];
            }
        }

        return array_map(static fn (array $row) => new self($row, $byConcept[$row['concept_id'] ?? ''] ?? []), $rows);
    }

    /** The frozen shape, so a run that starts under this policy freezes the same rules. */
    public function toArray(): array
    {
        return $this->row;
    }

    /** What a brief says about this row in a locale: its Use there and the linked language's Avoid, or its own. */
    public function languageFor(string $locale): GlossaryWording
    {
        $linked = $this->linkedIn($locale);

        return $this->inOutput($locale, $linked !== null ? $linked['forbidden'] : $this->countedAvoid($locale));
    }

    /** Every row checks its own Avoid, so a linked row does not answer for another. */
    public function checkedLanguage(string $locale): GlossaryWording
    {
        return $this->inOutput($locale, $this->countedAvoid($locale));
    }

    public function sourceRank(GlossaryWording $wording, string $locale): int
    {
        return match (true) {
            $wording->locale === $locale => 3,
            $wording->locale === explode('-', $locale)[0] => 2,
            ! isset($this->row['concept_id']) && GlossaryMatcher::sameLanguage($wording->locale, $locale) => 1,
            default => 0,
        };
    }

    public function noteIn(string $locale): ?string
    {
        return $this->note ?: $this->linkedIn($locale)['note'] ?? null;
    }

    public function protectedWording(GlossaryWording $source, string $sourceLocale, string $targetLocale): ?string
    {
        if (! $this->keepExact) {
            return null;
        }

        return self::translating($sourceLocale, $targetLocale) ? $this->useIn($targetLocale) : $source->use;
    }

    public function requiredWording(GlossaryWording $source, string $sourceLocale, string $targetLocale): ?string
    {
        return self::translating($sourceLocale, $targetLocale) ? $this->translationFor($targetLocale) : null;
    }

    /**
     * @param  list<string>  $avoid
     */
    private function inOutput(string $locale, array $avoid): GlossaryWording
    {
        return new GlossaryWording(GlossaryMatcher::normalizeLocale($locale), $this->useIn($locale), $avoid, [], (string) $this->row['term']);
    }

    /**
     * @return list<string>
     */
    private function countedAvoid(string $locale): array
    {
        $counts = GlossaryMatcher::sameLanguage((string) $this->row['source_locale'], $locale)
            || (! isset($this->row['concept_id']) && ! self::banned($this->row));

        return $counts ? ($this->row['forbidden'] ?? []) : [];
    }

    /**
     * The same concept's other row for a locale: the exact one, else its base language.
     *
     * @return Sibling|null
     */
    private function linkedIn(string $locale): ?array
    {
        $locale = GlossaryMatcher::normalizeLocale($locale);
        $base = explode('-', $locale)[0];
        $found = null;

        foreach ($this->siblings as $sibling) {
            if ($sibling['locale'] === $locale || ($sibling['locale'] === $base && $found === null)) {
                $found = $sibling;
            }
        }

        return $found !== null && $found['id'] !== ($this->row['id'] ?? null) ? $found : null;
    }

    /** The row's Use in an output language, the replacement for its Avoid wording. */
    private function useIn(string $locale): ?string
    {
        return $this->translationFor($locale)
            ?? (! self::banned($this->row) && ($this->keepExact || GlossaryMatcher::sameLanguage((string) $this->row['source_locale'], $locale)) ? (string) $this->row['term'] : null);
    }

    /** The exact locale's entry, else its base language's. Independent of the map's order. */
    private function translationFor(string $locale): ?string
    {
        $translations = $this->row['translations'] ?? [];
        ksort($translations, SORT_STRING);
        $wording = [];

        foreach ($translations as $key => $value) {
            if (trim($value) !== '') {
                $wording[GlossaryMatcher::normalizeLocale((string) $key)] ??= $value;
            }
        }

        $locale = GlossaryMatcher::normalizeLocale($locale);

        return $wording[$locale] ?? $wording[explode('-', $locale)[0]] ?? null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function banned(array $row): bool
    {
        return \in_array($row['term'], $row['forbidden'] ?? [], true);
    }
}
