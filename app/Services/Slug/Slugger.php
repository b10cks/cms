<?php

namespace App\Services\Slug;

use Illuminate\Support\Str;

/**
 * The one place a human-readable string becomes a URL-safe slug.
 *
 * Everything that used to roll its own regex — the content mutator, the Slug
 * cast, icon keys, tree-operation uniqueness, the frontend — now funnels through
 * here, because a slug produced by one implementation and validated or looked up
 * by another is a slug that eventually disagrees with itself.
 *
 * The frontend twin lives in resources/js/lib/slug.ts and must be changed in
 * lockstep; tests/fixtures/slug-cases.json is the contract both sides assert
 * against. See that file for the one documented divergence, which is limited to
 * input portable-ascii has a mapping for and a browser cannot reproduce.
 */
class Slugger
{
    /**
     * Matches the `max:75` rule on the content slug request validators, so a
     * generated slug can never be the thing that fails validation.
     */
    public const int CONTENT_SLUG_LENGTH = 75;

    /**
     * Primary subtags portable-ascii ships a transliteration map for.
     *
     * Anything outside this list folds with the English map, which is the same
     * behaviour as before this class existed.
     */
    private const array TRANSLITERATION_LANGUAGES = [
        'am', 'ar', 'az', 'be', 'bg', 'bn', 'cs', 'da', 'de', 'el', 'en', 'eo',
        'et', 'fa', 'fi', 'fr', 'hi', 'hr', 'hu', 'hy', 'it', 'ja', 'ka', 'kk',
        'ko', 'ky', 'lt', 'lv', 'mk', 'mn', 'my', 'nl', 'no', 'or', 'pl', 'ps',
        'pt', 'ro', 'ru', 'sk', 'sr', 'sv', 'th', 'tk', 'tr', 'uk', 'uz', 'vi',
        'zh',
    ];

    /**
     * Languages whose own code is not the portable-ascii key.
     */
    private const array LANGUAGE_ALIASES = [
        'nb' => 'no',
        'nn' => 'no',
        'iw' => 'he',
        'in' => 'id',
        'ji' => 'yi',
    ];

    /**
     * The word `&` becomes, per language.
     *
     * Dropping the ampersand instead would glue "Bed & Breakfast" into
     * "bed-breakfast", which reads as a different phrase.
     */
    private const array AMPERSAND = [
        'bg' => 'i', 'cs' => 'a', 'da' => 'og', 'de' => 'und', 'el' => 'kai',
        'es' => 'y', 'et' => 'ja', 'fi' => 'ja', 'fr' => 'et', 'hr' => 'i',
        'hu' => 'es', 'it' => 'e', 'lt' => 'ir', 'lv' => 'un', 'nl' => 'en',
        'no' => 'og', 'pl' => 'i', 'pt' => 'e', 'ro' => 'si', 'ru' => 'i',
        'sk' => 'a', 'sl' => 'in', 'sr' => 'i', 'sv' => 'och', 'tr' => 've',
        'uk' => 'i',
    ];

    /**
     * Symbols that carry a word and would otherwise vanish silently.
     */
    private const array SYMBOLS = [
        '@' => 'at',
        '%' => 'percent',
        '€' => 'eur',
        '£' => 'gbp',
        '$' => 'usd',
        '©' => 'c',
        '®' => 'r',
        '№' => 'no',
    ];

    /**
     * @param  string|null  $language  A BCP-47 tag or bare language code. The region
     *                                 subtag is dropped on purpose: `de-AT` maps ß to
     *                                 "sz", which is correct transliteration and wrong
     *                                 for a URL.
     * @param  int|null  $maxLength  Truncates on a separator boundary so the tail is a
     *                               whole word rather than a severed one.
     * @param  bool  $allowUnderscore  Underscores are a legal, meaningful slug
     *                                 character and survive by default. Pass false for
     *                                 the identifier slugs whose validators restrict
     *                                 them to `^[a-z0-9-]+$` (spaces, data sources,
     *                                 icon keys).
     */
    public function make(
        string $value,
        ?string $language = null,
        string $separator = '-',
        ?int $maxLength = null,
        bool $allowUnderscore = true,
    ): string {
        $language = $this->normalizeLanguage($language);
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = $this->expandSymbols($value, $language, $separator);
        $value = Str::ascii($value, $this->transliterationLanguage($language));
        $value = mb_strtolower($value, 'UTF-8');

        // Post-transliteration the string is ASCII, so a plain class is both
        // enough and immune to the \pL surprises of the previous regexes: a
        // Cyrillic slug that folded to nothing used to survive as raw UTF-8.
        $quoted = preg_quote($separator, '/');
        $keep = $allowUnderscore ? '_' : '';
        $value = preg_replace('/[^a-z0-9'.$keep.$quoted.']+/', $separator, $value) ?? '';
        $value = preg_replace('/'.$quoted.'+/', $separator, $value) ?? '';
        $value = trim($value, $separator);

        return $maxLength === null ? $value : $this->truncate($value, $separator, $maxLength);
    }

    /**
     * The slug for a piece of content, in the language that content is written in.
     *
     * Content slugs have no character rule beyond `max:75`, so underscores are
     * kept — they always were, and editors use them.
     */
    public function forContent(string $value, ?string $languageIso): string
    {
        return $this->make($value, $languageIso, '-', self::CONTENT_SLUG_LENGTH);
    }

    /**
     * The slug for a space-level identifier — a space, storage, data source or
     * icon key — whose validators allow only `^[a-z0-9-]+$`.
     */
    public function forIdentifier(string $value, ?string $language, ?int $maxLength = null): string
    {
        return $this->make($value, $language, '-', $maxLength, allowUnderscore: false);
    }

    /**
     * Resolve a slug that must not be empty.
     *
     * CJK, emoji and pure-punctuation titles all normalize to "", which the
     * callers that persist without validating would happily store — and an empty
     * slug collides with every sibling that has one.
     */
    public function makeWithFallback(
        string $value,
        string $fallback,
        ?string $language = null,
        string $separator = '-',
        ?int $maxLength = null,
    ): string {
        $slug = $this->make($value, $language, $separator, $maxLength);

        return $slug !== '' ? $slug : $fallback;
    }

    /**
     * Escape a slug for use as a `LIKE` prefix.
     *
     * Content slugs legitimately contain underscores, and `_` is a LIKE wildcard
     * matching any single character — without this, a prefix probe for "a_b"
     * also matches "axb" and the uniqueness check reports a collision that is
     * not there.
     */
    public function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    private function expandSymbols(string $value, ?string $language, string $separator): string
    {
        $symbols = self::SYMBOLS + ['&' => self::AMPERSAND[$language] ?? 'and'];
        $replacements = [];

        foreach ($symbols as $symbol => $word) {
            $replacements[$symbol] = $separator.$word.$separator;
        }

        return str_replace(array_keys($replacements), array_values($replacements), $value);
    }

    private function truncate(string $value, string $separator, int $maxLength): string
    {
        if ($maxLength <= 0 || mb_strlen($value) <= $maxLength) {
            return $value;
        }

        $cut = mb_substr($value, 0, $maxLength);
        $boundary = mb_strrpos($cut, $separator);

        // Only honour the boundary when it leaves something behind; a single
        // word longer than the limit still has to be cut somewhere.
        if ($boundary !== false && $boundary > 0) {
            $cut = mb_substr($cut, 0, $boundary);
        }

        return trim($cut, $separator);
    }

    private function normalizeLanguage(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }

        $primary = strtolower(trim(preg_split('/[-_]/', trim($language))[0] ?? ''));

        if ($primary === '') {
            return null;
        }

        return self::LANGUAGE_ALIASES[$primary] ?? $primary;
    }

    private function transliterationLanguage(?string $language): string
    {
        return \in_array($language, self::TRANSLITERATION_LANGUAGES, true) ? $language : 'en';
    }
}
