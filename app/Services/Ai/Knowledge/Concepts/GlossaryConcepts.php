<?php

namespace App\Services\Ai\Knowledge\Concepts;

use App\Exceptions\GlossaryConceptConflict;
use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossaryConcept;
use App\Models\Management\AiGlossaryTerm;
use App\Models\Management\Space;
use App\Services\Ai\Knowledge\GlossaryMatcher;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The only place that writes a glossary's rows. Editor endpoints, imports of every
 * format, the older term endpoints and accepted suggestions all go through it.
 *
 * One instance holds a glossary in memory. create(), update() and remove() change that
 * state and queue the writes; commit() stores them in bulk, so a write costs no query. A
 * refused write throws and changes nothing, and the caller may carry on with the next row.
 *
 * Three hold no matter how many callers run: one term per language of a row, no two rows
 * with the same wording in a language, and no new or changed note over {@see NOTE_LIMIT}.
 * A row that already shares a wording or holds a longer note, as an older glossary can,
 * stays editable until that wording or note changes.
 *
 * @phpstan-type Fields array{use: string|null, avoid: list<string>, also: list<string>}
 * @phpstan-type LanguageInput array{use?: string|null, avoid?: list<string>|null, also?: list<string>|null}
 * @phpstan-type RowBase array{keep_exact?: bool, note?: string|null, languages?: array<string, LanguageInput>}
 * @phpstan-type ConceptInput array{key?: string|null, keep_exact?: bool, note?: string|null, languages?: array<string, LanguageInput>, remove_languages?: list<string>, base?: RowBase}
 */
final class GlossaryConcepts
{
    public const NOTE_LIMIT = 200;

    private const CHUNK = 500;

    private const BLANK = ['use' => null, 'avoid' => [], 'also' => []];

    /** @var array<string, AiGlossaryConcept> */
    private array $concepts = [];

    /** @var array<string, string> concept key => concept id */
    private array $conceptIdsByKey = [];

    /** @var array<string, array<string, AiGlossaryTerm>> concept id => normalized locale => term */
    private array $terms = [];

    /** @var array<string, array<string, array<string, true>>> normalized locale => folded wording => concept ids */
    private array $wordings = [];

    /** @var array<string, true> concepts to store */
    private array $changedConcepts = [];

    /** @var array<string, true> */
    private array $removedConcepts = [];

    /** @var array<string, AiGlossaryTerm> terms to store */
    private array $changedTerms = [];

    /** @var array<string, true> */
    private array $removedTerms = [];

    private int $version;

    private function __construct(private readonly AiGlossary $glossary, private readonly ?string $defaultLocale)
    {
        // Read before the rows, so a write landing during the load counts as a change.
        $this->version = (int) AiGlossary::query()->whereKey($glossary->id)->value('write_version');

        foreach (AiGlossaryConcept::query()->where('glossary_id', $glossary->id)->get() as $concept) {
            $this->concepts[$concept->id] = $concept;

            if ($concept->key !== null) {
                $this->conceptIdsByKey[$concept->key] = $concept->id;
            }
        }

        foreach (AiGlossaryTerm::query()->inGlossary($glossary->id)->get() as $term) {
            $this->terms[$term->concept_id][$term->locale] = $term;
            $this->indexWording($term->locale, self::wordingOf(self::fieldsOf($term)), $term->concept_id);
        }
    }

    /**
     * Loads the glossary once. Holds no lock or transaction, so a streaming caller
     * may yield between rows; commit() refuses if the glossary moved meanwhile.
     */
    public static function open(AiGlossary $glossary): self
    {
        $space = $glossary->space_id !== null ? Space::query()->find($glossary->space_id) : null;

        return new self($glossary, $space?->settings->getDefaultLanguage());
    }

    /**
     * Runs one unit of writes under the glossary's lock and commits it.
     *
     * @template T
     *
     * @param  Closure(self): T  $work
     * @return T
     */
    public static function transaction(AiGlossary $glossary, Closure $work): mixed
    {
        return DB::connection($glossary->getConnectionName())->transaction(function () use ($glossary, $work) {
            AiGlossary::query()->lockForUpdate()->findOrFail($glossary->id);

            $concepts = self::open($glossary);
            $result = $work($concepts);
            $concepts->commit();

            return $result;
        });
    }

    public function hasRow(string $rowId): bool
    {
        return isset($this->terms[$rowId]);
    }

    public function rowIdForKey(string $key): ?string
    {
        return $this->conceptIdsByKey[$key] ?? null;
    }

    /**
     * What a row holds now, for a caller that decides how a file row applies.
     *
     * @return array{key: string|null, terms: array<string, string>} wording by normalized locale
     */
    public function rowFacts(string $rowId): array
    {
        return [
            'key' => $this->concepts[$rowId]->key ?? null,
            'terms' => array_map(static fn (AiGlossaryTerm $term) => self::wordingOf(self::fieldsOf($term)), $this->requireRow($rowId)),
        ];
    }

    /** How many stored things the queued writes touch. Zero means commit() has nothing to do. */
    public function pendingWrites(): int
    {
        return \count($this->changedConcepts) + \count($this->removedConcepts) + \count($this->changedTerms) + \count($this->removedTerms);
    }

    /** The row whose wording in this locale this is, ignoring case. */
    public function rowIdForWording(string $locale, string $wording): ?string
    {
        return array_key_first($this->wordings[GlossaryMatcher::normalizeLocale($locale)][mb_strtolower($wording)] ?? []);
    }

    /**
     * @param  ConceptInput  $input
     * @return string the new row's id
     *
     * @throws GlossaryConceptConflict
     */
    public function create(array $input): string
    {
        return $this->apply(null, [], $input, true);
    }

    /**
     * Omitted fields and languages keep their stored value. With `base`, the row as the
     * author read it, the write is refused if it would replace a value they never saw.
     *
     * @param  ConceptInput  $input
     *
     * @throws ModelNotFoundException for an unknown row
     * @throws GlossaryConceptConflict
     */
    public function update(string $rowId, array $input): string
    {
        $stored = $this->requireRow($rowId);

        if (isset($input['base'])) {
            $this->assertBaseCurrent($this->concepts[$rowId], $stored, $input, $input['base']);
        }

        return $this->apply($rowId, $stored, $input, false);
    }

    /**
     * Removes a row with all its languages.
     *
     * @throws ModelNotFoundException for an unknown row
     */
    public function remove(string $rowId): void
    {
        foreach ($this->requireRow($rowId) as $locale => $term) {
            $this->dropTerm($rowId, $locale, $term);
        }

        $concept = $this->concepts[$rowId];
        unset($this->terms[$rowId], $this->changedConcepts[$rowId], $this->concepts[$rowId], $this->conceptIdsByKey[$concept->key ?? '']);

        if ($concept->exists) {
            $this->removedConcepts[$rowId] = true;
        }
    }

    /**
     * Stores everything queued, in one transaction and in chunks, and moves the glossary's
     * write version on. Nothing queued does nothing.
     *
     * @throws GlossaryConceptConflict when the glossary changed since open()
     */
    public function commit(): void
    {
        if ($this->pendingWrites() === 0) {
            return;
        }

        DB::connection($this->glossary->getConnectionName())->transaction(function (): void {
            $current = (int) AiGlossary::query()->lockForUpdate()->whereKey($this->glossary->id)->value('write_version');

            if ($current !== $this->version) {
                throw new GlossaryConceptConflict(GlossaryConceptConflict::STALE, 'The glossary changed while this was being prepared. Run it again.');
            }

            $this->store();
            $this->version = $current + 1;
            AiGlossary::query()->whereKey($this->glossary->id)->update(['write_version' => $this->version]);
        });
    }

    /**
     * Only fields this write changes count, so saves to different fields still merge, and
     * writing the value already stored replaces nothing.
     *
     * @param  array<string, AiGlossaryTerm>  $stored
     * @param  ConceptInput  $input
     * @param  RowBase  $base
     *
     * @throws GlossaryConceptConflict
     */
    private function assertBaseCurrent(AiGlossaryConcept $concept, array $stored, array $input, array $base): void
    {
        $changes = [];
        $compare = static function (string $field, ?string $locale, mixed $seen, mixed $now, mixed $wanted) use (&$changes): void {
            if ($seen !== $now && $wanted !== $now) {
                $changes[] = ['field' => $field, 'locale' => $locale];
            }
        };

        if (\array_key_exists('note', $input)) {
            $compare('note', null, $this->cleanText($base['note'] ?? null), $this->cleanText($concept->note), $this->cleanText($input['note']));
        }

        if (\array_key_exists('keep_exact', $input)) {
            $compare('keep_exact', null, (bool) ($base['keep_exact'] ?? false), $concept->keep_exact, (bool) $input['keep_exact']);
        }

        $seen = [];

        foreach ($base['languages'] ?? [] as $code => $values) {
            $seen[GlossaryMatcher::normalizeLocale((string) $code)] = $this->merged(self::BLANK, $values);
        }

        $writes = [...$input['languages'] ?? [], ...array_fill_keys($input['remove_languages'] ?? [], self::BLANK)];

        foreach ($writes as $code => $values) {
            $locale = GlossaryMatcher::normalizeLocale((string) $code);
            $now = isset($stored[$locale]) ? $this->merged(self::BLANK, self::fieldsOf($stored[$locale])) : self::BLANK;
            $wanted = $this->merged($now, $values);

            foreach (array_keys(array_intersect_key(self::BLANK, $values)) as $field) {
                $compare($field, (string) $code, $seen[$locale][$field] ?? self::BLANK[$field], $now[$field], $wanted[$field]);
            }
        }

        if ($changes !== []) {
            throw new GlossaryConceptConflict(GlossaryConceptConflict::ROW_CHANGED, 'Someone else changed this row after you read it. Nothing was saved.', ['changes' => $changes]);
        }
    }

    /**
     * @param  array<string, AiGlossaryTerm>  $stored  the row's terms by normalized locale
     * @param  ConceptInput  $input
     */
    private function apply(?string $conceptId, array $stored, array $input, bool $creating): string
    {
        $languages = [];

        foreach ($input['languages'] ?? [] as $code => $values) {
            $languages[GlossaryMatcher::normalizeLocale((string) $code)] = ['code' => (string) $code, 'values' => $values];
        }

        $removals = array_flip(array_map(GlossaryMatcher::normalizeLocale(...), $input['remove_languages'] ?? []));
        $next = array_map(self::fieldsOf(...), array_diff_key($stored, $removals));

        foreach ($languages as $locale => ['values' => $values]) {
            $next[$locale] = $this->merged($next[$locale] ?? self::BLANK, $values);
        }

        $held = $conceptId !== null ? $this->concepts[$conceptId] : null;

        // Everything that can refuse runs before anything changes.
        $this->assertNoteFits($held, $input);
        $this->assertWritable($conceptId, $stored, $languages, $next, $input, $creating);
        $this->assertUseNotAvoided($stored, $languages, $next, $held?->keep_exact, (bool) ($input['keep_exact'] ?? $held->keep_exact ?? false));

        $conceptId = $this->writeConcept($conceptId, $input);

        foreach (array_intersect_key($stored, $removals) as $locale => $term) {
            $this->dropTerm($conceptId, $locale, $term);
        }

        foreach ($next as $locale => $fields) {
            $this->writeTerm($conceptId, $locale, $fields);
        }

        return $conceptId;
    }

    /**
     * The stored note stays whatever its length, so an older one survives a read and write back.
     *
     * @param  ConceptInput  $input
     *
     * @throws GlossaryConceptConflict
     */
    private function assertNoteFits(?AiGlossaryConcept $held, array $input): void
    {
        $note = $this->cleanText($input['note'] ?? null);

        if ($note !== null && mb_strlen($note) > self::NOTE_LIMIT && $note !== $this->cleanText($held?->note)) {
            throw new GlossaryConceptConflict(GlossaryConceptConflict::NOTE_TOO_LONG, 'The note is longer than '.self::NOTE_LIMIT.' characters.');
        }
    }

    /**
     * @param  array<string, AiGlossaryTerm>  $stored
     * @param  array<string, array{code: string, values: array<string, mixed>}>  $languages
     * @param  array<string, Fields>  $next
     * @param  ConceptInput  $input
     */
    private function assertWritable(?string $conceptId, array $stored, array $languages, array $next, array $input, bool $creating): void
    {
        if ($next === []) {
            throw new GlossaryConceptConflict(
                $creating ? GlossaryConceptConflict::TERM_REQUIRED : GlossaryConceptConflict::LAST_LANGUAGE,
                $creating ? 'A row needs wording to use or to avoid in at least one language.' : 'A row keeps at least one language. Delete the row instead.',
            );
        }

        foreach ($languages as $locale => ['code' => $code]) {
            if (! GlossaryLocales::isValidCode($code)) {
                throw new GlossaryConceptConflict(GlossaryConceptConflict::LANGUAGE_INVALID, "The language \"{$code}\" is not a valid locale.", ['locale' => $code]);
            }

            $wording = self::wordingOf($next[$locale]);

            if ($wording === null) {
                throw new GlossaryConceptConflict(GlossaryConceptConflict::TERM_REQUIRED, "The language {$code} needs wording to use or to avoid.", ['locale' => $code]);
            }

            $held = isset($stored[$locale]) ? self::wordingOf(self::fieldsOf($stored[$locale])) : null;

            if ($held === null || mb_strtolower($wording) !== mb_strtolower($held)) {
                $this->assertWordingFree($conceptId, $locale, $code, $wording);
            }
        }

        $key = \array_key_exists('key', $input) ? $this->cleanText($input['key']) : null;
        $holder = $key !== null ? ($this->conceptIdsByKey[$key] ?? null) : null;

        if ($holder !== null && $holder !== $conceptId) {
            throw new GlossaryConceptConflict(GlossaryConceptConflict::KEY_TAKEN, "The key {$key} belongs to another concept.", [
                'key' => $key,
                'concept' => $this->describeRow($holder),
            ]);
        }
    }

    /**
     * A Use its own Avoid would flag cannot be right, by the same rule the checker reads:
     * case and a `*` count. What a row already held stays until someone changes that pair,
     * so an older contradictory row can still take an unrelated edit.
     *
     * @param  array<string, AiGlossaryTerm>  $stored
     * @param  array<string, array{code: string, values: array<string, mixed>}>  $languages
     * @param  array<string, Fields>  $next
     */
    private function assertUseNotAvoided(array $stored, array $languages, array $next, ?bool $heldCase, bool $case): void
    {
        foreach ($heldCase === $case ? array_intersect_key($next, $languages) : $next as $locale => $fields) {
            foreach ($fields['use'] !== null ? $fields['avoid'] : [] as $avoid) {
                if (GlossaryMatcher::findBanned($fields['use'], $avoid, $case) === null) {
                    continue;
                }

                $was = isset($stored[$locale]) ? self::fieldsOf($stored[$locale]) : null;

                if ($was !== null && $was['use'] === $fields['use'] && $heldCase !== null && \in_array($avoid, $was['avoid'], true) && GlossaryMatcher::findBanned($was['use'], $avoid, $heldCase) !== null) {
                    continue;
                }

                $code = $languages[$locale]['code'] ?? $locale;

                throw new GlossaryConceptConflict(GlossaryConceptConflict::USE_AVOIDED, "The wording \"{$fields['use']}\" in {$code} is covered by its own Avoid entry \"{$avoid}\".", ['locale' => $code, 'wording' => $fields['use'], 'avoid' => $avoid]);
            }
        }
    }

    private function assertWordingFree(?string $conceptId, string $locale, string $code, string $wording): void
    {
        foreach (array_keys($this->wordings[$locale][mb_strtolower($wording)] ?? []) as $holder) {
            if ($holder !== $conceptId) {
                throw new GlossaryConceptConflict(GlossaryConceptConflict::WORDING_TAKEN, "The wording \"{$wording}\" in {$code} belongs to another concept.", [
                    'locale' => $code,
                    'wording' => $wording,
                    'concept' => $this->describeRow($holder),
                ]);
            }
        }
    }

    /**
     * @return array{id: string, key: string|null, term: string}
     */
    private function describeRow(string $rowId): array
    {
        $terms = $this->requireRow($rowId);
        ksort($terms, SORT_STRING);
        $default = $this->defaultLocale !== null ? GlossaryMatcher::normalizeLocale($this->defaultLocale) : '';

        return [
            'id' => $rowId,
            'key' => $this->concepts[$rowId]->key,
            'term' => (string) self::wordingOf(self::fieldsOf($terms[$default] ?? reset($terms))),
        ];
    }

    /**
     * @param  Fields  $fields
     * @param  array<string, mixed>  $values
     * @return Fields
     */
    private function merged(array $fields, array $values): array
    {
        if (\array_key_exists('use', $values)) {
            $fields['use'] = $this->cleanText($values['use']);
        }

        foreach (['avoid', 'also'] as $field) {
            if (\array_key_exists($field, $values)) {
                $fields[$field] = $this->cleanList($values[$field]);
            }
        }

        return $fields;
    }

    /**
     * @param  ConceptInput  $input
     */
    private function writeConcept(?string $conceptId, array $input): string
    {
        $concept = $conceptId !== null ? $this->concepts[$conceptId] : new AiGlossaryConcept(['glossary_id' => $this->glossary->id, 'keep_exact' => false]);

        if ($conceptId === null) {
            $concept->id = $concept->newUniqueId();
            $this->concepts[$concept->id] = $concept;
            $this->changedConcepts[$concept->id] = true;
        }

        $previousKey = $concept->key;

        foreach (['key', 'note'] as $field) {
            if (\array_key_exists($field, $input)) {
                $concept->{$field} = $this->cleanText($input[$field]);
            }
        }

        if (\array_key_exists('keep_exact', $input)) {
            $concept->keep_exact = (bool) $input['keep_exact'];
        }

        if ($concept->isDirty()) {
            $this->changedConcepts[$concept->id] = true;
        }

        if ($previousKey !== $concept->key) {
            unset($this->conceptIdsByKey[$previousKey ?? '']);

            if ($concept->key !== null) {
                $this->conceptIdsByKey[$concept->key] = $concept->id;
            }
        }

        return $concept->id;
    }

    /**
     * @param  Fields  $fields
     */
    private function writeTerm(string $conceptId, string $locale, array $fields): void
    {
        $term = $this->terms[$conceptId][$locale] ?? null;

        if ($term !== null) {
            $was = self::fieldsOf($term);

            if ($was === $fields) {
                return;
            }

            $this->unindexWording($locale, self::wordingOf($was), $conceptId);
        } else {
            $term = new AiGlossaryTerm(['concept_id' => $conceptId, 'locale' => $locale]);
            $term->id = $term->newUniqueId();
            $this->terms[$conceptId][$locale] = $term;
        }

        $term->fill(['preferred' => $fields['use'], 'forbidden' => $fields['avoid'], 'aliases' => $fields['also']]);
        $this->changedTerms[$term->id] = $term;
        $this->indexWording($locale, self::wordingOf($fields), $conceptId);
    }

    private function dropTerm(string $conceptId, string $locale, AiGlossaryTerm $term): void
    {
        $this->unindexWording($locale, self::wordingOf(self::fieldsOf($term)), $conceptId);
        unset($this->terms[$conceptId][$locale], $this->changedTerms[$term->id]);

        if ($term->exists) {
            $this->removedTerms[$term->id] = true;
        }
    }

    private function store(): void
    {
        $now = now()->toDateTimeString();
        $connection = $this->glossary->getConnection();
        $terms = $connection->table('ai_glossary_terms');
        $concepts = $connection->table('ai_glossary_concepts');

        foreach (array_chunk(array_keys($this->removedTerms), self::CHUNK) as $ids) {
            (clone $terms)->whereIn('id', $ids)->delete();
        }

        foreach (array_chunk(array_keys($this->removedConcepts), self::CHUNK) as $ids) {
            (clone $concepts)->whereIn('id', $ids)->delete();
        }

        [$updated, $added] = $this->byExistence(array_map(fn (string $id) => $this->concepts[$id], array_keys($this->changedConcepts)));

        foreach (array_chunk($added, self::CHUNK) as $chunk) {
            (clone $concepts)->insert(array_map(fn (AiGlossaryConcept $concept) => $this->conceptRow($concept, $now), $chunk));
        }

        foreach (array_chunk($updated, self::CHUNK) as $chunk) {
            (clone $concepts)->upsert(array_map(fn (AiGlossaryConcept $concept) => $this->conceptRow($concept, $now), $chunk), ['id'], ['key', 'note', 'keep_exact', 'updated_at']);
        }

        [$updated, $added] = $this->byExistence($this->changedTerms);

        foreach (array_chunk($added, self::CHUNK) as $chunk) {
            (clone $terms)->insert(array_map(fn (AiGlossaryTerm $term) => $this->termRow($term, $now), $chunk));
        }

        foreach (array_chunk($updated, self::CHUNK) as $chunk) {
            (clone $terms)->upsert(array_map(fn (AiGlossaryTerm $term) => $this->termRow($term, $now), $chunk), ['id'], ['preferred', 'forbidden', 'aliases', 'updated_at']);
        }

        foreach ([...array_map(fn (string $id) => $this->concepts[$id], array_keys($this->changedConcepts)), ...array_values($this->changedTerms)] as $model) {
            $model->exists = true;
            $model->syncOriginal();
        }

        $this->changedConcepts = $this->removedConcepts = $this->changedTerms = $this->removedTerms = [];
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  array<array-key, T>  $models
     * @return array{0: list<T>, 1: list<T>} the stored ones, then the new ones
     */
    private function byExistence(array $models): array
    {
        $stored = array_values(array_filter($models, static fn ($model) => $model->exists));
        $new = array_values(array_filter($models, static fn ($model) => ! $model->exists));

        return [$stored, $new];
    }

    /**
     * @return array<string, mixed>
     */
    private function conceptRow(AiGlossaryConcept $concept, string $now): array
    {
        return ['id' => $concept->id, 'glossary_id' => $concept->glossary_id, 'key' => $concept->key, 'note' => $concept->note, 'keep_exact' => (int) $concept->keep_exact, 'created_at' => $now, 'updated_at' => $now];
    }

    /**
     * @return array<string, mixed>
     */
    private function termRow(AiGlossaryTerm $term, string $now): array
    {
        $attributes = $term->getAttributes();

        return [
            'id' => $term->id,
            'concept_id' => $term->concept_id,
            'locale' => $term->locale,
            'preferred' => $attributes['preferred'] ?? null,
            'forbidden' => $attributes['forbidden'] ?? null,
            'aliases' => $attributes['aliases'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array<string, AiGlossaryTerm> by normalized locale
     */
    private function requireRow(string $rowId): array
    {
        return $this->terms[$rowId] ?? throw (new ModelNotFoundException)->setModel(AiGlossaryConcept::class, [$rowId]);
    }

    /**
     * @return Fields
     */
    private static function fieldsOf(AiGlossaryTerm $term): array
    {
        return ['use' => $term->preferred, 'avoid' => $term->forbidden ?? [], 'also' => $term->aliases ?? []];
    }

    /**
     * @param  Fields  $fields
     */
    private static function wordingOf(array $fields): ?string
    {
        return $fields['use'] ?? $fields['avoid'][0] ?? null;
    }

    private function indexWording(string $locale, ?string $wording, string $conceptId): void
    {
        if ($wording !== null) {
            $this->wordings[$locale][mb_strtolower($wording)][$conceptId] = true;
        }
    }

    private function unindexWording(string $locale, ?string $wording, string $conceptId): void
    {
        if ($wording !== null) {
            unset($this->wordings[$locale][mb_strtolower($wording)][$conceptId]);
        }
    }

    /**
     * @param  list<string>|null  $values
     * @return list<string>
     */
    private function cleanList(?array $values): array
    {
        return array_values(array_unique(array_filter(array_map(static fn ($value) => trim((string) $value), $values ?? []), static fn (string $value) => $value !== '')));
    }

    private function cleanText(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
