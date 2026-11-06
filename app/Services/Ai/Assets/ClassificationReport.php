<?php

namespace App\Services\Ai\Assets;

/**
 * What automatic classification did with every requested `language.field` key:
 *
 * - `written`: saved to the asset
 * - `kept`: valid output that changed nothing, as the value was the same or an
 *   editor filled the field meanwhile
 * - `intentionally_empty`: alt text of an image marked decorative
 * - `uninferable`: the model returned an empty string, the image does not settle it
 * - `rejected`: `[{key, reason}]`, output that broke the field's rules
 * - `missing`: the model did not return the key
 *
 * `status` is `complete` with no open values, `partial` with open values next
 * to usable output, and `none` when nothing usable came back. A language with
 * nothing to fill is `satisfied`.
 */
final class ClassificationReport
{
    public const COMPLETE = 'complete';

    public const PARTIAL = 'partial';

    public const NONE = 'none';

    public const SATISFIED = 'satisfied';

    /**
     * @param  list<string>  $languages  language keys the classification was asked for
     * @param  list<string>  $targetKeys  requested `language.field` keys
     * @param  array{fields: array<string, array<string, string>>, tags: list<string>, rejected: array<string, string>, uninferable: list<string>, unknown_tags: list<string>, tags_malformed: bool}  $parsed
     * @param  list<string>  $written  keys saved to the asset
     * @param  list<string>  $writtenTags  tag IDs newly on the asset
     * @param  array{shown: int, total: int, truncated: bool}  $taxonomy
     * @return array<string, mixed>
     */
    public static function build(
        array $languages,
        array $targetKeys,
        array $parsed,
        array $written,
        array $writtenTags,
        bool $decorative,
        bool $tagsRequested,
        array $taxonomy,
    ): array {
        $returned = [];
        foreach ($parsed['fields'] as $language => $values) {
            foreach ($values as $field => $value) {
                $returned[] = "{$language}.{$field}";
            }
        }

        $empty = $decorative ? array_values(array_filter($returned, fn (string $key) => \in_array(self::field($key), ['alt', 'alt_text'], true))) : [];
        $rejected = array_keys($parsed['rejected']);
        $missing = array_values(array_diff($targetKeys, $returned, $rejected, $parsed['uninferable']));
        $open = [...$missing, ...$rejected, ...$parsed['uninferable']];
        $usable = $returned !== [] || $writtenTags !== [] || $parsed['tags'] !== [];
        $tagsOpen = $tagsRequested && $parsed['tags_malformed'];

        return [
            'status' => self::status($open !== [] || $tagsOpen, $usable),
            'requested' => $targetKeys,
            'written' => array_values(array_diff($written, $empty)),
            'kept' => array_values(array_diff($returned, $written, $empty)),
            'intentionally_empty' => $empty,
            'uninferable' => $parsed['uninferable'],
            'rejected' => array_map(fn (string $key, string $reason) => ['key' => $key, 'reason' => $reason], $rejected, $parsed['rejected']),
            'missing' => $missing,
            'languages' => self::languages($languages, $targetKeys, $returned, $open),
            'tags' => [
                'requested' => $tagsRequested,
                'written' => $writtenTags,
                'unknown' => $parsed['unknown_tags'],
                'malformed' => $tagsOpen,
                'taxonomy' => $taxonomy,
            ],
        ];
    }

    /**
     * The part worth keeping on the asset: what stayed open.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function summary(array $report): array
    {
        return array_intersect_key($report, array_flip(['status', 'intentionally_empty', 'uninferable', 'rejected', 'missing']));
    }

    /**
     * @param  list<string>  $languages
     * @param  list<string>  $targetKeys
     * @param  list<string>  $returned
     * @param  list<string>  $open
     * @return array<string, array{status: string, open: list<string>}>
     */
    private static function languages(array $languages, array $targetKeys, array $returned, array $open): array
    {
        $summary = [];

        foreach ($languages as $language) {
            $ofLanguage = fn (string $key) => str_starts_with($key, "{$language}.");
            $openHere = array_values(array_map(self::field(...), array_filter($open, $ofLanguage)));

            $summary[$language] = [
                'status' => array_filter($targetKeys, $ofLanguage) === []
                    ? self::SATISFIED
                    : self::status($openHere !== [], array_filter($returned, $ofLanguage) !== []),
                'open' => $openHere,
            ];
        }

        return $summary;
    }

    private static function status(bool $open, bool $usable): string
    {
        return ! $open ? self::COMPLETE : ($usable ? self::PARTIAL : self::NONE);
    }

    private static function field(string $key): string
    {
        return explode('.', $key, 2)[1] ?? '';
    }
}
