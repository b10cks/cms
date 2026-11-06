<?php

namespace App\Services\Ai\Knowledge\Concepts;

use App\Models\Management\Space;
use App\Services\Ai\Knowledge\GlossaryMatcher;

/**
 * Which languages a glossary row screen shows. A space's enabled languages drive
 * it, default first. Terms in other languages stay stored and show as unused. A
 * team has no language settings, so its glossaries use whatever is stored.
 */
final class GlossaryLocales
{
    /** @var array<string, string> normalized locale => code the screen uses */
    private array $codes = [];

    /** @var array<string, true> */
    private array $enabled = [];

    /** @var array<string, true> */
    private array $stored = [];

    private const CODE = '/^[A-Za-z]{2,3}([-_][A-Za-z0-9]{2,8})*$/';

    private const MAX_CODE_LENGTH = 20;

    public readonly ?string $default;

    /** What a glossary takes as a language code, from any source. */
    public static function isValidCode(string $code): bool
    {
        return \strlen($code) <= self::MAX_CODE_LENGTH && preg_match(self::CODE, $code) === 1;
    }

    /**
     * @param  list<string>  $stored  the locales the glossary's terms use, in any spelling
     */
    public function __construct(?Space $space, array $stored)
    {
        foreach ($space?->settings->getEnabledLanguages() ?? [] as $code) {
            $normalized = GlossaryMatcher::normalizeLocale($code);
            $this->codes[$normalized] ??= $code;
            $this->enabled[$normalized] = true;
        }

        sort($stored, SORT_STRING);

        foreach ($stored as $code) {
            $normalized = GlossaryMatcher::normalizeLocale($code);
            $this->codes[$normalized] ??= $code;
            $this->stored[$normalized] = true;
        }

        $this->default = $space?->settings->getDefaultLanguage() ?? ($stored[0] ?? null);
    }

    /** The code a stored locale appears under, so a row's languages line up with the list. */
    public function code(string $locale): string
    {
        return $this->codes[GlossaryMatcher::normalizeLocale($locale)] ?? $locale;
    }

    /**
     * @return list<array{code: string, default: bool, enabled: bool, unused: bool}>
     */
    public function available(): array
    {
        $default = $this->default !== null ? GlossaryMatcher::normalizeLocale($this->default) : null;
        $list = [];

        foreach ($this->codes as $normalized => $code) {
            $enabled = isset($this->enabled[$normalized]) || $this->enabled === [];
            $list[] = ['code' => $code, 'default' => $normalized === $default, 'enabled' => $enabled, 'unused' => ! $enabled && ! $this->servesEnabled($normalized)];
        }

        return $list;
    }

    /**
     * For every available locale: its own term, a base language term that serves it
     * (`de` serves `de-AT`), or nothing.
     *
     * @param  array<string, true>  $own  the row's normalized locales
     * @return array<string, 'own'|'fallback'|'missing'>
     */
    public function coverage(array $own): array
    {
        $coverage = [];

        foreach ($this->codes as $normalized => $code) {
            $coverage[$code] = match (true) {
                isset($own[$normalized]) => 'own',
                isset($own[explode('-', $normalized)[0]]) => 'fallback',
                default => 'missing',
            };
        }

        return $coverage;
    }

    /**
     * @param  array<string, true>  $own  the row's normalized locales
     */
    public function covers(array $own, string $locale): bool
    {
        $normalized = GlossaryMatcher::normalizeLocale($locale);

        return isset($own[$normalized]) || isset($own[explode('-', $normalized)[0]]);
    }

    private function servesEnabled(string $locale): bool
    {
        foreach ($this->enabled as $enabled => $_) {
            if (explode('-', $enabled)[0] === $locale) {
                return true;
            }
        }

        return false;
    }
}
