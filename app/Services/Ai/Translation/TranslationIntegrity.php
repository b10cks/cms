<?php

namespace App\Services\Ai\Translation;

/**
 * Deterministic checks that a translation keeps what must not be translated:
 * template placeholders, HTML tags and their link targets, markdown link
 * targets and inline code. A translation that breaks one is refused, so the
 * model fixes it before anyone reviews it.
 */
final class TranslationIntegrity
{
    /** `{name}`, `{{ name }}`, `${name}`, `%s`, `%1$s`, `%d`. */
    private const PLACEHOLDER = '/\{\{\s*[^{}]+?\s*\}\}|\$\{[^{}]+\}|\{[\p{L}\p{N}_.\-]+\}|%(?:\d+\$)?[sdif]/u';

    private const TAG = '/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*>/';

    private const URL_ATTRIBUTE = '/\b(?:href|src)\s*=\s*("([^"]*)"|\'([^\']*)\')/i';

    private const MARKDOWN_LINK = '/\]\(\s*([^)\s]+)[^)]*\)/u';

    private const INLINE_CODE = '/`[^`\n]+`/u';

    /**
     * @return list<string> what the translation lost or added, empty when it is fine
     */
    public static function problems(string $source, string $target): array
    {
        $problems = [];

        foreach ([
            'placeholders' => self::all(self::PLACEHOLDER, $source, $target, fn (array $match) => preg_replace('/\s+/', '', $match[0])),
            'HTML tags' => self::all(self::TAG, $source, $target, fn (array $match) => '<'.$match[1].strtolower($match[2]).'>'),
            'link targets' => self::all(self::URL_ATTRIBUTE, $source, $target, fn (array $match) => ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '')),
            'markdown link targets' => self::all(self::MARKDOWN_LINK, $source, $target, fn (array $match) => $match[1]),
            'inline code' => self::all(self::INLINE_CODE, $source, $target, fn (array $match) => $match[0]),
        ] as $label => [$expected, $found]) {
            $missing = self::difference($expected, $found);
            $extra = self::difference($found, $expected);

            if ($missing !== []) {
                $problems[] = "keep the {$label} ".implode(', ', $missing);
            }
            if ($extra !== []) {
                $problems[] = "the source has no {$label} ".implode(', ', $extra);
            }
        }

        return $problems;
    }

    /**
     * @param  callable(array<int, string>): string  $key
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function all(string $pattern, string $source, string $target, callable $key): array
    {
        $collect = function (string $text) use ($pattern, $key): array {
            preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

            return array_map($key, $matches);
        };

        return [$collect($source), $collect($target)];
    }

    /**
     * Multiset difference: values of $a not matched one to one in $b.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private static function difference(array $a, array $b): array
    {
        $counts = array_count_values($b);
        $missing = [];

        foreach ($a as $value) {
            if (($counts[$value] ?? 0) > 0) {
                $counts[$value]--;
            } else {
                $missing[] = $value;
            }
        }

        return array_values(array_unique($missing));
    }
}
