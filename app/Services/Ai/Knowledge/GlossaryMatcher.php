<?php

namespace App\Services\Ai\Knowledge;

/**
 * Finds glossary terms in text. Matching respects word boundaries (letters and
 * digits of any script) and each term's case sensitivity. One compiled matcher
 * per term set, memoised by the set's hash.
 */
final class GlossaryMatcher
{
    /** @var array<string, self> */
    private static array $compiled = [];

    /** @var list<array{term: GlossaryTerm, pattern: string}> */
    private array $patterns = [];

    /**
     * @param  list<GlossaryTerm>  $terms
     */
    private function __construct(array $terms)
    {
        foreach ($terms as $term) {
            $this->patterns[] = ['term' => $term, 'pattern' => self::pattern($term->term, $term->caseSensitive)];
        }
    }

    /**
     * @param  list<GlossaryTerm>  $terms
     */
    public static function for(array $terms): self
    {
        $hash = hash('sha256', serialize(array_map(static fn (GlossaryTerm $term) => $term->toArray(), $terms)));

        if (\count(self::$compiled) > 50) {
            self::$compiled = [];
        }

        return self::$compiled[$hash] ??= new self($terms);
    }

    public static function pattern(string $phrase, bool $caseSensitive): string
    {
        return '/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u'.($caseSensitive ? '' : 'i');
    }

    public static function contains(string $text, string $phrase, bool $caseSensitive): bool
    {
        return $phrase !== '' && preg_match(self::pattern($phrase, $caseSensitive), $text) === 1;
    }

    /**
     * Terms of the given source locale found in the text. An empty locale
     * matches every locale.
     *
     * @return list<GlossaryTerm>
     */
    public function match(string $text, ?string $locale = null): array
    {
        $found = [];

        foreach ($this->patterns as ['term' => $term, 'pattern' => $pattern]) {
            if ($locale !== null && $locale !== '' && ! self::sameLanguage($term->sourceLocale, $locale)) {
                continue;
            }

            if (preg_match($pattern, $text) === 1) {
                $found[] = $term;
            }
        }

        return $found;
    }

    /** `de` matches `de-AT`: glossaries are usually kept per language. */
    public static function sameLanguage(string $a, string $b): bool
    {
        $a = strtolower(str_replace('_', '-', $a));
        $b = strtolower(str_replace('_', '-', $b));

        return $a === $b || explode('-', $a)[0] === explode('-', $b)[0];
    }
}
