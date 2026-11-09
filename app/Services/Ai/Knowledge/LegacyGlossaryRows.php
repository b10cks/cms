<?php

namespace App\Services\Ai\Knowledge;

use App\Services\Ai\Knowledge\Concepts\GlossaryConcepts;

/**
 * Reads glossary rows from before `ai_glossary_terms` was normalized: one row per
 * language with a `term`, a `translations` map and copies of the note and flags. Used
 * by the one-time migration and by imports of the older file format, so both give the
 * same answer.
 *
 * A row's own language always wins over a translation another row held. A translation
 * that disagrees with it stays as Also written as, so no wording goes missing. Avoid
 * wording of a row with no concept counted in every output language, so it reaches each
 * language it names. A ban named wording of its own language only, and its term stays
 * the first Avoid entry, which is what the row goes by. Case follows Keep exact, so an
 * old `case_sensitive` value is not read.
 *
 * @phpstan-import-type ConceptInput from GlossaryConcepts
 *
 * @phpstan-type Language array{use: string|null, avoid: list<string>, also: list<string>}
 * @phpstan-type Concept array{id: string|null, glossary_id: string|null, keep_exact: bool, note: string|null, term_ids: array<string, string>, languages: array<string, Language>}
 */
final class LegacyGlossaryRows
{
    /**
     * A row of the older format as input for the writer, for older files. Blank values
     * are left out, so a stored value stays.
     *
     * @param  array<string, mixed>  $row  source_locale and term at least
     * @return ConceptInput
     */
    public static function input(array $row): array
    {
        $concept = self::concept([$row]);
        $input = [];

        if (\array_key_exists('do_not_translate', $row)) {
            $input['keep_exact'] = $concept['keep_exact'];
        }

        if ($concept['note'] !== null) {
            $input['note'] = $concept['note'];
        }

        foreach ($concept['languages'] as $locale => $language) {
            $input['languages'][$locale] = array_filter($language, static fn ($value, string $field) => $field === 'use' || $value !== [], ARRAY_FILTER_USE_BOTH);
        }

        return $input;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<Concept> one per stored concept, and one per row without a concept
     */
    public static function concepts(array $rows): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            $groups[$row['concept_id'] ?? 'row:'.($row['id'] ?? $index)][] = $row;
        }

        return array_map(self::concept(...), array_values($groups));
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     * @return Concept
     */
    private static function concept(array $rows): array
    {
        usort($rows, static fn (array $a, array $b) => [GlossaryMatcher::normalizeLocale((string) $a['source_locale']), (string) $a['term']] <=> [GlossaryMatcher::normalizeLocale((string) $b['source_locale']), (string) $b['term']]);

        $linked = ($rows[0]['concept_id'] ?? null) !== null;
        $languages = [];
        $termIds = [];

        foreach ($rows as $row) {
            $avoid = self::strings($row['forbidden'] ?? []);
            $banned = \in_array($row['term'], $avoid, true);
            self::add($languages, (string) $row['source_locale'], $banned ? null : (string) $row['term'], $banned ? array_values(array_unique([$row['term'], ...$avoid])) : $avoid, self::strings($row['source_aliases'] ?? []));

            if (isset($row['id'])) {
                $termIds[GlossaryMatcher::normalizeLocale((string) $row['source_locale'])] ??= (string) $row['id'];
            }
        }

        foreach ($rows as $row) {
            $avoid = self::strings($row['forbidden'] ?? []);
            $spreads = ! $linked && ! \in_array($row['term'], $avoid, true);

            foreach (self::wordingMap($row['translations'] ?? []) as $locale => $wording) {
                self::add($languages, (string) $locale, $wording, $spreads ? $avoid : [], []);
            }
        }

        $keepExact = array_filter($rows, static fn (array $row) => (bool) ($row['do_not_translate'] ?? false)) !== [];

        return [
            'id' => $rows[0]['concept_id'] ?? $rows[0]['id'] ?? null,
            'glossary_id' => $rows[0]['glossary_id'] ?? null,
            'keep_exact' => $keepExact,
            'term_ids' => $termIds,
            'note' => array_values(array_filter(array_map(static fn (array $row) => (string) ($row['note'] ?? ''), $rows)))[0] ?? null,
            'languages' => array_map(static function (array $language) {
                $language['avoid'] = array_values(array_filter($language['avoid'], static fn (string $wording) => $wording !== $language['use']));

                return $language;
            }, $languages),
        ];
    }

    /**
     * @param  array<string, Language>  $languages
     * @param  list<string>  $avoid
     * @param  list<string>  $also
     */
    private static function add(array &$languages, string $code, ?string $use, array $avoid, array $also): void
    {
        $locale = GlossaryMatcher::normalizeLocale($code);
        $held = $languages[$locale] ?? ['use' => $use, 'avoid' => [], 'also' => []];

        if ($use !== null && $held['use'] !== $use) {
            $also[] = $use;
        }

        $languages[$locale] = [
            'use' => $held['use'],
            'avoid' => array_values(array_unique([...$held['avoid'], ...$avoid])),
            'also' => array_values(array_unique([...$held['also'], ...$also])),
        ];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return array_values(array_filter(\is_array($values) ? $values : [], static fn ($value) => \is_string($value) && trim($value) !== ''));
    }

    /**
     * @return array<string, string>
     */
    private static function wordingMap(mixed $values): array
    {
        return array_filter(\is_array($values) ? $values : [], static fn ($value) => \is_string($value) && trim($value) !== '');
    }
}
