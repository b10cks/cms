<?php

namespace App\Services\Ai\Knowledge;

use App\Exceptions\GlossaryConceptConflict;
use App\Models\Management\AiGlossaryTerm;
use App\Services\Ai\Knowledge\Concepts\GlossaryConcepts;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Reads glossary rows from before `ai_glossary_terms` was normalized: one row per
 * language with a `term`, a `translations` map and copies of the note and flags. Used
 * by the one-time migration and by callers of the older term format, so both give the
 * same answer. Policies frozen in that shape keep their rules, see {@see FrozenGlossaryRow}.
 *
 * A row's own language always wins over a translation another row held. A translation
 * that disagrees with it stays as Also written as, so no wording goes missing. Avoid
 * wording of a row with no concept counted in every output language, so it reaches each
 * language it names. A ban named wording of its own language only, and its term stays
 * the first Avoid entry, which is what the row goes by. `case_sensitive` was a setting of
 * its own, so it is kept where it differs from Keep exact.
 *
 * @phpstan-import-type ConceptInput from GlossaryConcepts
 *
 * @phpstan-type Language array{use: string|null, avoid: list<string>, also: list<string>}
 * @phpstan-type Concept array{id: string|null, glossary_id: string|null, keep_exact: bool, case_sensitive: bool|null, note: string|null, term_ids: array<string, string>, languages: array<string, Language>}
 */
final class LegacyGlossaryRows
{
    /**
     * A row of the older format as input for the writer, for older files and the term
     * endpoints. Blank values are left out, so a stored value stays.
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

        if (\array_key_exists('do_not_translate', $row) || \array_key_exists('case_sensitive', $row)) {
            $input['case_sensitive'] = (bool) ($row['case_sensitive'] ?? false);
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
     * The writer's input for a change sent to one language through the older term
     * endpoint, from the row as it is under the lock. `translations` holds Use and
     * nothing else, so it can only change Use: a language it leaves out loses its Use,
     * and stays if it holds Avoid or Also written as, which only the row endpoints remove.
     * A language with Also written as and no Avoid cannot stand without a Use, so it stays
     * whole.
     *
     * @param  array<string, mixed>  $fields  what the request sent
     * @return ConceptInput
     *
     * @throws GlossaryConceptConflict when the term moves onto a language the row already has
     * @throws ModelNotFoundException when the term went meanwhile
     */
    public static function termChange(GlossaryConcepts $concepts, string $rowId, string $termId, array $fields): array
    {
        $languages = $concepts->rowLanguages($rowId);
        $held = self::localeOf($languages, $termId);
        $term = $languages[$held];
        $locale = GlossaryMatcher::normalizeLocale((string) ($fields['source_locale'] ?? $held));

        if ($locale !== $held && isset($languages[$locale])) {
            throw new GlossaryConceptConflict(GlossaryConceptConflict::LANGUAGE_TAKEN, "This row already has the language {$locale}. Change or delete that one first.", ['locale' => $locale]);
        }

        $avoid = \array_key_exists('forbidden', $fields) ? self::strings($fields['forbidden']) : $term['avoid'];
        // A ban goes by its first Avoid entry, so a changed list keeps the wording this shape shows.
        $use = $fields['term'] ?? $term['use'] ?? $term['avoid'][0] ?? null;
        $own = [];

        if (\array_key_exists('term', $fields) || \array_key_exists('forbidden', $fields)) {
            $own['use'] = \in_array($use, $avoid, true) ? null : $use;
        }

        if (\array_key_exists('forbidden', $fields)) {
            $own['avoid'] = $avoid;
        }

        if (\array_key_exists('source_aliases', $fields)) {
            $own['also'] = self::strings($fields['source_aliases']);
        }

        $input = ['languages' => [$locale => $own]];

        if ($locale !== $held) {
            $input['languages'][$locale] = ['use' => $term['use'], 'avoid' => $term['avoid'], 'also' => $term['also'], ...$own];
            $input['remove_languages'][] = $held;
        }

        if (\array_key_exists('translations', $fields)) {
            foreach (self::wordingMap($fields['translations']) as $code => $wording) {
                $input['languages'][GlossaryMatcher::normalizeLocale((string) $code)] ??= ['use' => $wording];
            }

            foreach ($languages as $sibling => $values) {
                if ($sibling === $held || isset($input['languages'][$sibling]) || $values['use'] === null) {
                    continue;
                }

                if ($values['avoid'] !== []) {
                    $input['languages'][$sibling] = ['use' => null];
                } elseif ($values['also'] === []) {
                    $input['remove_languages'][] = $sibling;
                }
            }
        }

        if (\array_key_exists('note', $fields)) {
            $input['note'] = $fields['note'];
        }

        if (\array_key_exists('do_not_translate', $fields)) {
            $input['keep_exact'] = (bool) $fields['do_not_translate'];
        }

        // This format keeps case apart: changing one flag leaves the other as it reads now.
        if (\array_key_exists('case_sensitive', $fields) || \array_key_exists('do_not_translate', $fields)) {
            $input['case_sensitive'] = (bool) ($fields['case_sensitive'] ?? $concepts->rowCaseSensitive($rowId));
        }

        return $input;
    }

    /**
     * The language a term of the older endpoints holds, from the row as it is under the lock.
     *
     * @param  array<string, array{id: string}>  $languages
     *
     * @throws ModelNotFoundException when the term went meanwhile
     */
    public static function localeOf(array $languages, string $termId): string
    {
        return array_find_key($languages, static fn (array $language) => $language['id'] === $termId)
            ?? throw (new ModelNotFoundException)->setModel(AiGlossaryTerm::class, [$termId]);
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
        $caseSensitive = array_filter($rows, static fn (array $row) => (bool) ($row['case_sensitive'] ?? false)) !== [];

        return [
            'id' => $rows[0]['concept_id'] ?? $rows[0]['id'] ?? null,
            'glossary_id' => $rows[0]['glossary_id'] ?? null,
            'keep_exact' => $keepExact,
            'case_sensitive' => $caseSensitive === $keepExact ? null : $caseSensitive,
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
