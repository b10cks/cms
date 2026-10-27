<?php

namespace App\Services\Ai\Knowledge;

use App\Models\Management\AiGlossaryTerm;

/** A resolved glossary term, frozen into a run as plain data. */
final readonly class GlossaryTerm
{
    /**
     * @param  array<string, string>  $translations  locale => required target term
     * @param  list<string>  $forbidden
     */
    public function __construct(
        public string $term,
        public string $sourceLocale,
        public array $translations = [],
        public bool $caseSensitive = false,
        public bool $doNotTranslate = false,
        public array $forbidden = [],
        public ?string $note = null,
    ) {}

    public static function fromModel(AiGlossaryTerm $term): self
    {
        return new self(
            $term->term,
            $term->source_locale,
            array_filter($term->translations ?? [], 'is_string'),
            $term->case_sensitive,
            $term->do_not_translate,
            array_values(array_filter($term->forbidden ?? [], 'is_string')),
            $term->note,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['term'],
            (string) $data['source_locale'],
            $data['translations'] ?? [],
            (bool) ($data['case_sensitive'] ?? false),
            (bool) ($data['do_not_translate'] ?? false),
            $data['forbidden'] ?? [],
            $data['note'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'term' => $this->term,
            'source_locale' => $this->sourceLocale,
            'translations' => $this->translations,
            'case_sensitive' => $this->caseSensitive,
            'do_not_translate' => $this->doNotTranslate,
            'forbidden' => $this->forbidden,
            'note' => $this->note,
        ];
    }

    /** Same-term identity used for shadowing across layers. */
    public function key(): string
    {
        return strtolower($this->sourceLocale).'|'.mb_strtolower($this->term);
    }
}
