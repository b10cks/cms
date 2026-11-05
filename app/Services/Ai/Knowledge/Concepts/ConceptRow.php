<?php

namespace App\Services\Ai\Knowledge\Concepts;

use App\Models\Management\AiGlossaryConcept;
use App\Models\Management\AiGlossaryTerm;

/**
 * One glossary row as an editor sees it: Keep exact, the note and what each language says.
 */
final readonly class ConceptRow
{
    /**
     * @param  array<string, array{use: string|null, avoid: list<string>, also: list<string>}>  $languages  by locale code
     * @param  array<string, string>  $coverage  by locale code
     */
    public function __construct(
        public string $id,
        public ?string $key,
        public bool $keepExact,
        public ?string $note,
        public array $languages,
        public array $coverage,
    ) {}

    /** A concept with its terms loaded. */
    public static function of(AiGlossaryConcept $concept, GlossaryLocales $locales): self
    {
        $terms = $concept->terms->sortBy('locale', SORT_STRING)->keyBy('locale');
        $languages = [];

        foreach ($terms as $term) {
            /** @var AiGlossaryTerm $term */
            $languages[$locales->code($term->locale)] = [
                'use' => $term->preferred,
                'avoid' => $term->forbidden ?? [],
                'also' => $term->aliases ?? [],
            ];
        }

        return new self(
            $concept->id,
            $concept->key,
            $concept->keep_exact,
            $concept->note,
            $languages,
            $locales->coverage(array_fill_keys($terms->keys()->all(), true)),
        );
    }
}
