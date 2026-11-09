<?php

namespace App\Services\Ai\Knowledge;

use App\Models\Management\AiGlossaryConcept;
use App\Models\Management\AiGlossaryTerm;
use LogicException;

/**
 * One glossary row, frozen into a run as plain data. Prompts, lookup, the checker and
 * translation memory all ask {@see languageFor()}, so they agree on which language of
 * the row counts for a locale: the exact one, else its base language. A language
 * replaces its base whole. A sibling region never stands in, nor a region for its base.
 * Keep exact also makes Use and Avoid match with exact case.
 */
readonly class GlossaryConcept
{
    /** @var array<string, GlossaryWording> by normalized locale */
    public array $languages;

    /**
     * @param  iterable<GlossaryWording>  $languages
     */
    public function __construct(
        iterable $languages,
        public bool $keepExact = false,
        public ?string $note = null,
        public ?string $id = null,
        public ?string $glossaryId = null,
    ) {
        $byLocale = [];

        foreach ($languages as $language) {
            $byLocale[$language->locale] = $language;
        }

        ksort($byLocale, SORT_STRING);
        $this->languages = $byLocale;
    }

    /**
     * For tests and readers: `['de' => ['use' => 'Bett', 'avoid' => [...], 'also' => [...]]]`.
     *
     * @param  array<string, array{use?: string|null, avoid?: list<string>|null, also?: list<string>|null}>  $languages
     */
    public static function of(array $languages, bool $keepExact = false, ?string $note = null, ?string $id = null, ?string $glossaryId = null): self
    {
        $wordings = [];

        foreach ($languages as $locale => $values) {
            $wordings[] = new GlossaryWording(
                GlossaryMatcher::normalizeLocale((string) $locale),
                $values['use'] ?? null,
                array_values(array_filter($values['avoid'] ?? [], 'is_string')),
                array_values(array_filter($values['also'] ?? [], 'is_string')),
            );
        }

        return new self($wordings, $keepExact, $note, $id, $glossaryId);
    }

    /** A stored concept with its terms loaded. */
    public static function fromModel(AiGlossaryConcept $concept): self
    {
        $wordings = $concept->terms->map(static fn (AiGlossaryTerm $term) => new GlossaryWording(
            $term->locale,
            $term->preferred,
            array_values(array_filter($term->forbidden ?? [], 'is_string')),
            array_values(array_filter($term->aliases ?? [], 'is_string')),
        ));

        return new self($wordings, $concept->keep_exact, $concept->note, $concept->id, $concept->glossary_id);
    }

    /**
     * Rows as frozen into a snapshot or a run. A `case_sensitive` key an older snapshot
     * holds is ignored: case follows Keep exact.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<self>
     *
     * @throws LogicException for rows frozen before they became concepts (one row per language)
     */
    public static function listFromFrozen(array $rows): array
    {
        if (isset($rows[0]) && ! \array_key_exists('languages', $rows[0])) {
            throw new LogicException('This work was pinned to a glossary from before rows became concepts, which is no longer read. Start it again.');
        }

        return array_map(static fn (array $row) => self::of(
            $row['languages'],
            (bool) ($row['keep_exact'] ?? false),
            $row['note'] ?? null,
            $row['id'] ?? null,
            $row['glossary_id'] ?? null,
        ), $rows);
    }

    /**
     * The frozen run shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'glossary_id' => $this->glossaryId,
            'keep_exact' => $this->keepExact,
            'note' => $this->note,
            'languages' => array_map(static fn (GlossaryWording $language) => ['use' => $language->use, 'avoid' => $language->avoid, 'also' => $language->also], $this->languages),
        ];
    }

    /** The language of this row that counts for a locale. */
    public function languageFor(string $locale): ?GlossaryWording
    {
        $locale = GlossaryMatcher::normalizeLocale($locale);

        return $this->languages[$locale] ?? $this->languages[explode('-', $locale)[0]] ?? null;
    }

    /** The language whose Avoid wording an output in this locale must not hold, for the checker. */
    public function checkedLanguage(string $locale): ?GlossaryWording
    {
        return $this->languageFor($locale);
    }

    /** How a language of this row applies to a text in a normalized locale: 3 exact, 2 it is the text's base language, 0 not at all. */
    public function sourceRank(GlossaryWording $wording, string $locale): int
    {
        if ($this->languageFor($locale) !== $wording) {
            return 0;
        }

        return $wording->locale === $locale ? 3 : 2;
    }

    /** The note an agent reads when writing into this locale. */
    public function noteIn(string $locale): ?string
    {
        return $this->note;
    }

    /**
     * The wording a Keep exact name takes in a target locale, null for any other row.
     * Translating uses the target language's own Use and otherwise keeps the source
     * wording. Within one locale the name stays. A ban keeps nothing.
     */
    public function protectedWording(GlossaryWording $source, string $sourceLocale, string $targetLocale): ?string
    {
        if (! $this->keepExact) {
            return null;
        }

        return self::translating($sourceLocale, $targetLocale)
            ? ($this->languageFor($targetLocale)->use ?? $source->use)
            : $source->use;
    }

    /** The Use a translation must hold, when the target has its own language in this row. */
    public function requiredWording(GlossaryWording $source, string $sourceLocale, string $targetLocale): ?string
    {
        $target = $this->languageFor($targetLocale);

        return self::translating($sourceLocale, $targetLocale) && $target !== $source ? $target?->use : null;
    }

    /** A regional adaptation (`de-DE` to `de-AT`) counts: it has localized wording too. */
    public static function translating(string $sourceLocale, string $targetLocale): bool
    {
        return GlossaryMatcher::normalizeLocale($sourceLocale) !== GlossaryMatcher::normalizeLocale($targetLocale);
    }
}
