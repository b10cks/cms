<?php

namespace App\Services\Space;

class SpaceI18nSettingsService
{
    public const MODE_OVERLAY = 'overlay';

    public const MODE_INDEPENDENT = 'independent';

    public function normalize(array $settings): array
    {
        $defaultLanguage = $this->normalizeLanguageIso($settings['default_language'] ?? 'en') ?? 'en';
        $languages = [];
        $seen = [];

        foreach ($settings['languages'] ?? [] as $language) {
            if (! \is_array($language)) {
                continue;
            }

            $code = $this->normalizeLanguageIso($language['code'] ?? null);
            if ($code === null || $code === $defaultLanguage || isset($seen[$code])) {
                continue;
            }

            $seen[$code] = true;
            $languages[] = [
                'code' => $code,
                'name' => trim((string) ($language['name'] ?? strtoupper($code))),
                'fallback_language' => $this->normalizeLanguageIso($language['fallback_language'] ?? null),
            ];
        }

        return [
            ...$settings,
            'default_language' => $defaultLanguage,
            'i18n_mode' => $this->normalizeMode($settings['i18n_mode'] ?? self::MODE_OVERLAY),
            'languages' => $languages,
        ];
    }

    public function validate(array $settings): array
    {
        $errors = [];
        $defaultLanguage = $settings['default_language'] ?? 'en';
        $languages = $settings['languages'] ?? [];
        $languageMap = [];

        foreach ($languages as $language) {
            if (! \is_array($language) || empty($language['code'])) {
                continue;
            }

            $languageMap[$language['code']] = $language;
        }

        $enabledLanguages = [$defaultLanguage, ...array_keys($languageMap)];

        foreach (array_values($languages) as $index => $language) {
            if (! \is_array($language)) {
                continue;
            }

            $code = $language['code'] ?? null;
            $fallbackLanguage = $language['fallback_language'] ?? null;

            if (! $code || $fallbackLanguage === null) {
                continue;
            }

            if ($fallbackLanguage === $code) {
                $errors["settings.languages.{$index}.fallback_language"] = 'A language cannot fallback to itself.';

                continue;
            }

            if (! \in_array($fallbackLanguage, $enabledLanguages, true)) {
                $errors["settings.languages.{$index}.fallback_language"] = 'Fallback language must be enabled in this space.';

                continue;
            }

            if (! $this->isValidFallbackChain($code, $languageMap, $defaultLanguage)) {
                $errors["settings.languages.{$index}.fallback_language"] = 'Fallback chains must resolve to the default language without cycles.';
            }
        }

        return $errors;
    }

    private function isValidFallbackChain(string $languageIso, array $languageMap, string $defaultLanguage): bool
    {
        $visited = [$languageIso => true];
        $fallbackLanguage = $languageMap[$languageIso]['fallback_language'] ?? null;

        while ($fallbackLanguage !== null && $fallbackLanguage !== $defaultLanguage) {
            if (isset($visited[$fallbackLanguage])) {
                return false;
            }

            if (! isset($languageMap[$fallbackLanguage])) {
                return false;
            }

            $visited[$fallbackLanguage] = true;
            $fallbackLanguage = $languageMap[$fallbackLanguage]['fallback_language'] ?? null;
        }

        return true;
    }

    public function normalizeLanguageIso(?string $languageIso): ?string
    {
        if ($languageIso === null) {
            return null;
        }

        $normalized = strtolower(trim($languageIso));

        return $normalized !== '' ? $normalized : null;
    }

    public function normalizeMode(?string $mode): string
    {
        return \in_array($mode, [self::MODE_OVERLAY, self::MODE_INDEPENDENT], true)
            ? $mode
            : self::MODE_OVERLAY;
    }
}
