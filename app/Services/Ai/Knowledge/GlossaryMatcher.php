<?php

namespace App\Services\Ai\Knowledge;

/**
 * Finds glossary terms in text. A term is found by its Use, by how it is also
 * written and by its Avoid wording. Matching respects word boundaries (letters and
 * digits of any script) and each term's case sensitivity, and also-written wording
 * ignores case. One compiled matcher per term set, memoised by the set's hash.
 */
final class GlossaryMatcher
{
    /** @var array<string, self> */
    private static array $compiled = [];

    /** @var list<array{concept: GlossaryConcept, wording: GlossaryWording, patterns: list<array{0: string, 1: bool}>}> */
    private array $languages = [];

    /**
     * @param  list<GlossaryConcept>  $concepts
     */
    private function __construct(array $concepts)
    {
        foreach ($concepts as $concept) {
            foreach ($concept->languages as $wording) {
                $this->languages[] = [
                    'concept' => $concept,
                    'wording' => $wording,
                    // In this order, so wording listed twice counts as the term's own.
                    'patterns' => [
                        ...($wording->use !== null ? [[self::pattern($wording->use, $concept->keepExact), false]] : []),
                        ...array_map(static fn (string $also) => [self::pattern($also, false), false], $wording->also),
                        ...array_map(static fn (string $avoid) => [self::avoidPattern($avoid, $concept->keepExact), true], array_filter($wording->avoid, static fn (string $avoid) => trim($avoid) !== '')),
                    ],
                ];
            }
        }
    }

    /**
     * @param  list<GlossaryConcept>  $concepts
     */
    public static function for(array $concepts): self
    {
        $hash = hash('sha256', serialize(array_map(static fn (GlossaryConcept $concept) => $concept->toArray(), $concepts)));

        if (\count(self::$compiled) > 50) {
            self::$compiled = [];
        }

        return self::$compiled[$hash] ??= new self($concepts);
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
     * The text that matches an Avoid entry, or null. A word of the entry that ends in
     * `*` matches any word that starts with it (`klimaneutral*` finds
     * `klimaneutrale`). The left word boundary stays and case follows the term. A `*`
     * anywhere else in a word is plain text, and so is a `*` that has no word before it.
     */
    public static function findBanned(string $text, string $entry, bool $caseSensitive): ?string
    {
        if (trim($entry) === '') {
            return null;
        }

        return preg_match(self::avoidPattern($entry, $caseSensitive), $text, $match) === 1 ? $match[0] : null;
    }

    private static function avoidPattern(string $entry, bool $caseSensitive): string
    {
        $parts = preg_split('/(\s+)/u', $entry, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $pattern = implode('', array_map(
            static fn (string $part) => mb_strlen($part) > 1 && str_ends_with($part, '*') && trim($part) === $part
                ? preg_quote(mb_substr($part, 0, -1), '/').'[\p{L}\p{N}]*'
                : preg_quote($part, '/'),
            $parts,
        ));

        return '/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])/u'.($caseSensitive ? '' : 'i');
    }

    /**
     * Rows that apply to the source locale and are found in the text. See {@see find()}.
     *
     * @return list<GlossaryConcept>
     */
    public function match(string $text, ?string $locale = null): array
    {
        return array_column($this->find($text, $locale), 'concept');
    }

    /**
     * Rows that apply to the source locale and are found in the text, with the language
     * that matched and where. An empty locale matches every locale. Otherwise the row says
     * which language applies ({@see GlossaryConcept::sourceRank()}), and
     * the exact locale beats a base language that holds the same wording in another row.
     * A span marked `avoid` is a match on Avoid wording.
     *
     * The longest match wins: a wording inside the wording of another found term
     * (`Wolkenbett` inside `Großes Wolkenbett`) is no match.
     *
     * @return list<array{concept: GlossaryConcept, wording: GlossaryWording, spans: list<array{start: int, end: int, text: string, avoid: bool}>}>
     */
    public function find(string $text, ?string $locale = null): array
    {
        $locale = $locale !== null && $locale !== '' ? self::normalizeLocale($locale) : null;
        $found = [];
        $best = [];

        foreach ($this->languages as $index => ['concept' => $concept, 'wording' => $wording, 'patterns' => $patterns]) {
            $rank = $locale !== null ? $concept->sourceRank($wording, $locale) : 1;

            if ($rank === 0) {
                continue;
            }

            $spans = [];

            foreach ($patterns as [$pattern, $avoid]) {
                preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

                foreach ($matches[0] as [$match, $start]) {
                    $spans[$start.':'.($start + \strlen($match))] ??= ['start' => $start, 'end' => $start + \strlen($match), 'text' => $match, 'avoid' => $avoid];
                }
            }

            if ($spans === []) {
                continue;
            }

            $found[$index] = [$concept, $wording, $rank, $spans];

            foreach (self::identities($concept, $wording) as $identity) {
                $best[$identity] = max($best[$identity] ?? 0, $rank);
            }
        }

        $found = array_filter($found, static function (array $hit) use ($best): bool {
            foreach (self::identities($hit[0], $hit[1]) as $identity) {
                if ($hit[2] < $best[$identity]) {
                    return false;
                }
            }

            return true;
        });

        $covered = self::coveredSpans(array_column($found, 3));
        $hits = [];

        foreach ($found as [$concept, $wording, , $spans]) {
            $spans = array_values(array_diff_key($spans, $covered));

            if ($spans !== []) {
                usort($spans, static fn (array $a, array $b) => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
                $hits[] = ['concept' => $concept, 'wording' => $wording, 'spans' => $spans];
            }
        }

        return $hits;
    }

    /**
     * The spans inside a longer span. The same span twice is no containment.
     *
     * @param  list<array<string, array{start: int, end: int}>>  $spanSets  keyed by position
     * @return array<string, true>
     */
    private static function coveredSpans(array $spanSets): array
    {
        $unique = array_merge(...($spanSets ?: [[]]));
        uasort($unique, static fn (array $a, array $b) => [$a['start'], -$a['end']] <=> [$b['start'], -$b['end']]);

        $covered = [];
        $reach = -1;

        foreach ($unique as $key => $span) {
            if ($span['end'] <= $reach) {
                $covered[$key] = true;
            } else {
                $reach = $span['end'];
            }
        }

        return $covered;
    }

    /**
     * @return list<string>
     */
    private static function identities(GlossaryConcept $concept, GlossaryWording $wording): array
    {
        return [
            'wording:'.mb_strtolower($wording->name()),
            ...($concept->id !== null ? ["concept:{$concept->id}"] : []),
        ];
    }

    /** `de` and `de-AT` are one language. */
    public static function sameLanguage(string $a, string $b): bool
    {
        return explode('-', self::normalizeLocale($a))[0] === explode('-', self::normalizeLocale($b))[0];
    }

    /** `de_AT` and `DE-at` are the same locale. */
    public static function normalizeLocale(string $locale): string
    {
        return strtolower(str_replace('_', '-', trim($locale)));
    }
}
