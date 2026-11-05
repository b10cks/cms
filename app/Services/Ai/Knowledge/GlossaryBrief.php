<?php

namespace App\Services\Ai\Knowledge;

/**
 * The glossary as an agent reads it, in a prompt and on `get_entry`: one plain line per
 * row the text activates. It says what to write, what never to use and the row's note.
 * `lookup_glossary` and the text check answer with the same rule. It is wording, not
 * meaning: nothing checks that a note was followed, and {@see bans()} tells a writer
 * about wording its source never mentioned, within a limit, so output is still checked.
 *
 * @phpstan-type Hit array{concept: GlossaryConcept, wording: GlossaryWording, target: GlossaryWording|null, spans: list<array{start: int, end: int, text: string, avoid: bool}>}
 * @phpstan-type Rule array{term: string, avoided: bool, keep: string|null, write: string|null, in: string|null, never: list<string>, note: string|null, natural: bool}
 */
final class GlossaryBrief
{
    /** Enough to steer a writer without pulling a large glossary into every prompt. */
    private const MAX_BANS = 40;

    private const MAX_BAN_CHARS = 600;

    /**
     * @param  list<Hit>  $hits
     * @param  string|null  $sourceLocale  null when the text has no one language: each term counts in the one it matched
     * @param  string  $where  what the terms were found in, as the intro names it
     */
    public static function render(array $hits, ?string $sourceLocale, string $targetLocale, string $where = 'the source'): ?string
    {
        $lines = [];
        $noted = false;

        foreach ($hits as $hit) {
            $rule = self::rule($hit, $sourceLocale, $targetLocale);

            if ($rule['natural'] && $rule['note'] === null) {
                continue;
            }

            $noted = $noted || $rule['note'] !== null;
            $lines['- '.self::instruction($rule)] = true;
        }

        if ($lines === []) {
            return null;
        }

        return implode("\n", [
            "Terms found in {$where}".($sourceLocale !== null ? " ({$sourceLocale})" : '').'. Follow each line.',
            ...array_map('strval', array_keys($lines)),
            ...($noted ? ['A note is for your judgment: it applies only where it says so, and nothing checks it.'] : []),
        ]);
    }

    /**
     * Avoid wording of rows the text did not activate, so a writer does not use it for
     * the first time and only learn afterwards. Wording with no replacement comes first.
     * Null when there is none. A long list says it is cut.
     *
     * @param  list<GlossaryConcept>  $concepts
     * @param  list<Hit>  $hits  already told, so left out
     */
    public static function bans(array $concepts, array $hits, string $targetLocale): ?string
    {
        $told = array_flip(array_filter(array_map(static fn (array $hit) => $hit['concept']->id, $hits)));
        $entries = [];

        foreach ($concepts as $concept) {
            $target = isset($told[$concept->id]) ? null : $concept->languageFor($targetLocale);

            foreach ($target->avoid ?? [] as $wording) {
                $entry = "\"{$wording}\"".($target->use !== null ? " (write \"{$target->use}\")" : '');
                $entries[$entry] = [$target->isBan() ? 0 : 1, mb_strtolower($wording), $entry];
            }
        }

        if ($entries === []) {
            return null;
        }

        sort($entries);
        $listed = [];
        $length = 0;
        $wildcard = false;

        foreach ($entries as [, $lowered, $entry]) {
            $length += mb_strlen($entry) + 2;

            if (\count($listed) >= self::MAX_BANS || $length > self::MAX_BAN_CHARS) {
                break;
            }

            $listed[] = $entry;
            $wildcard = $wildcard || str_contains($lowered, '*');
        }

        $cut = \count($listed) < \count($entries);

        return "Also never write in {$targetLocale}: ".implode(', ', $listed).'.'
            .($wildcard ? ' A * covers longer forms.' : '')
            .($cut ? ' This list is cut short, and the rest is checked after you write.' : '');
    }

    /**
     * What one activated row asks for. A source that wrote only Avoid wording is told
     * never to use that wording, and to write the row's Use instead if it has one. A row
     * with nothing for the output language is `natural`: its source wording is no rule there.
     *
     * @param  Hit  $hit
     * @return Rule
     */
    public static function rule(array $hit, ?string $sourceLocale, string $targetLocale): array
    {
        ['concept' => $concept, 'wording' => $wording] = $hit;
        $source = $sourceLocale ?? $wording->locale;
        $target = $targetLocale !== '' ? $targetLocale : $source;
        $translating = GlossaryConcept::translating($source, $target);

        $avoided = array_column(array_filter($hit['spans'], static fn (array $span) => $span['avoid']), 'text');
        $named = $avoided !== [] && \count($avoided) === \count($hit['spans']) ? $avoided[0] : null;

        $protected = $concept->protectedWording($wording, $source, $target);
        $keep = $protected !== null && $protected === $wording->use ? $protected : null;
        $write = $keep === null ? ($protected ?? $concept->requiredWording($wording, $source, $target)) : null;
        $targetWording = $concept->languageFor($target);

        if ($keep === null && $write === null && $named !== null) {
            $write = $targetWording?->use;
        }

        $never = array_values(array_filter(
            $targetWording->avoid ?? [],
            static fn (string $avoid) => $named === null || GlossaryMatcher::findBanned($named, $avoid, false) === null,
        ));

        return [
            'term' => $named ?? $wording->name(),
            'avoided' => $named !== null,
            'keep' => $keep,
            'write' => $write,
            'in' => $translating ? $target : null,
            'never' => $never,
            'note' => $concept->noteIn($target) ?: null,
            'natural' => $translating && $named === null && $keep === null && $write === null && $never === [] && $targetWording?->use === null,
        ];
    }

    /**
     * @param  Rule  $rule
     */
    public static function instruction(array $rule): string
    {
        $parts = array_filter([
            $rule['natural'] ? 'translate naturally' : null,
            $rule['avoided'] ? 'never use' : null,
            $rule['keep'] !== null ? "keep exactly as \"{$rule['keep']}\"" : null,
            $rule['write'] !== null ? "write \"{$rule['write']}\"".($rule['in'] !== null ? " in {$rule['in']}" : '') : null,
            $rule['never'] !== [] ? 'never use '.self::quoted($rule['never']) : null,
            $rule['note'] !== null ? "note: {$rule['note']}" : null,
        ]);

        return "\"{$rule['term']}\": ".($parts !== [] ? implode('; ', $parts) : 'use this wording');
    }

    /**
     * @param  list<string>  $wordings
     */
    private static function quoted(array $wordings): string
    {
        return implode(', ', array_map(static fn (string $wording) => "\"{$wording}\"", $wordings));
    }
}
