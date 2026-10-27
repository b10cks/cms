<?php

namespace App\Services\Ai\Knowledge;

/**
 * Deterministic check of generated text against the glossary. Findings are
 * flagged to the editor, never fixed silently.
 */
final class GlossaryChecker
{
    public const MISSING_TERM = 'missing_term';

    public const TRANSLATED_DNT = 'translated_do_not_translate';

    public const FORBIDDEN_VARIANT = 'forbidden_variant';

    /**
     * @param  list<GlossaryTerm>  $terms  every resolved term; source matches are computed here
     * @return list<array{type: string, term: string, expected?: string, found?: string}>
     */
    public function check(string $source, string $output, string $sourceLocale, string $targetLocale, array $terms): array
    {
        $findings = [];
        $translating = ! GlossaryMatcher::sameLanguage($sourceLocale, $targetLocale);

        foreach (GlossaryMatcher::for($terms)->match($source, $sourceLocale) as $term) {
            if ($term->doNotTranslate) {
                if (! GlossaryMatcher::contains($output, $term->term, $term->caseSensitive)) {
                    $findings[] = ['type' => self::TRANSLATED_DNT, 'term' => $term->term, 'expected' => $term->term];
                }

                continue;
            }

            $required = $translating ? $this->translationFor($term, $targetLocale) : null;
            if ($required !== null && ! GlossaryMatcher::contains($output, $required, $term->caseSensitive)) {
                $findings[] = ['type' => self::MISSING_TERM, 'term' => $term->term, 'expected' => $required];
            }
        }

        foreach ($terms as $term) {
            foreach ($term->forbidden as $variant) {
                if (GlossaryMatcher::contains($output, $variant, $term->caseSensitive)) {
                    $findings[] = [
                        'type' => self::FORBIDDEN_VARIANT,
                        'term' => $term->term,
                        'expected' => $translating ? ($this->translationFor($term, $targetLocale) ?? $term->term) : $term->term,
                        'found' => $variant,
                    ];
                }
            }
        }

        return $findings;
    }

    private function translationFor(GlossaryTerm $term, string $locale): ?string
    {
        foreach ($term->translations as $key => $value) {
            if (GlossaryMatcher::sameLanguage((string) $key, $locale) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
