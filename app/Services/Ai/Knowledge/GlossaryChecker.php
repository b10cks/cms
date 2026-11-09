<?php

namespace App\Services\Ai\Knowledge;

/**
 * Deterministic check of generated text against the glossary. Avoid wording and
 * Keep exact names are violations: they go back to the agent, and what remains is
 * flagged to whoever publishes. Missing Use wording is a hint for the agent only,
 * because an inflected form is often correct and this check matches exactly. An Avoid
 * entry that ends in `*` covers longer forms ({@see GlossaryMatcher::findBanned()}).
 */
final class GlossaryChecker
{
    public const MISSING_TERM = 'missing_term';

    public const TRANSLATED_DNT = 'translated_do_not_translate';

    public const FORBIDDEN_VARIANT = 'forbidden_variant';

    /**
     * Whether a finding needs a correction or a reasoned override. Keyed on the
     * type, so findings stored before this rule read the same way.
     *
     * @param  array<string, mixed>  $finding
     */
    public static function isBlocking(array $finding): bool
    {
        return ($finding['type'] ?? null) !== self::MISSING_TERM;
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param  list<T>  $findings
     * @return list<T>
     */
    public static function blocking(array $findings): array
    {
        return array_values(array_filter($findings, self::isBlocking(...)));
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param  list<T>  $findings
     * @return list<T>
     */
    public static function hints(array $findings): array
    {
        return array_values(array_filter($findings, static fn (array $finding) => ! self::isBlocking($finding)));
    }

    /**
     * Stable identity of a finding, for naming it in an override.
     *
     * @param  array<string, string>  $finding
     */
    public static function findingId(array $finding): string
    {
        $identity = array_intersect_key($finding, array_flip(['type', 'term', 'expected', 'found']));
        ksort($identity, SORT_STRING);

        return substr(hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 32);
    }

    /**
     * @param  list<GlossaryConcept>  $concepts  every resolved row; source matches are computed here
     * @return list<array{type: string, term: string, expected?: string, found?: string}>
     */
    public function check(string $source, string $output, string $sourceLocale, string $targetLocale, array $concepts): array
    {
        $findings = [];

        foreach (GlossaryMatcher::for($concepts)->find($source, $sourceLocale) as ['concept' => $concept, 'wording' => $wording]) {
            $protected = $concept->protectedWording($wording, $sourceLocale, $targetLocale);

            if ($protected !== null) {
                if (! GlossaryMatcher::contains($output, $protected, $concept->keepExact)) {
                    $findings[] = ['type' => self::TRANSLATED_DNT, 'term' => $wording->name(), 'expected' => $protected];
                }

                continue;
            }

            $required = $concept->requiredWording($wording, $sourceLocale, $targetLocale);

            if ($required !== null && ! GlossaryMatcher::contains($output, $required, $concept->keepExact)) {
                $findings[] = ['type' => self::MISSING_TERM, 'term' => $wording->name(), 'expected' => $required];
            }
        }

        foreach ($concepts as $concept) {
            $target = $concept->checkedLanguage($targetLocale);

            foreach ($target->avoid ?? [] as $variant) {
                $matched = GlossaryMatcher::findBanned($output, $variant, $concept->keepExact);

                if ($matched !== null) {
                    $findings[] = [
                        'type' => self::FORBIDDEN_VARIANT,
                        'term' => $target->name(),
                        ...($target->use !== null ? ['expected' => $target->use] : []),
                        'found' => str_contains($variant, '*') ? $matched : $variant,
                    ];
                }
            }
        }

        return $findings;
    }
}
