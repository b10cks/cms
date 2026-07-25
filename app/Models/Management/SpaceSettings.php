<?php

namespace App\Models\Management;

use App\Models\Settings;
use App\Services\Space\SpaceI18nSettingsService;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Validation\Rule;

/**
 * @param  $region  string
 * @param  $defaultLanguage  string
 * @param  $languages  array
 * @param  $asset_fields  array
 * @param  $environments  array
 * @param  $visual_editor  bool
 * @param  $search_driver  string
 * @param  $slug_strategy  string
 * @param  $ai  array
 */
class SpaceSettings extends Settings
{
    protected array $defaults = [
        'region' => 'eu',
        'default_block' => null,
        'default_language' => 'en',
        'i18n_mode' => 'overlay',
        'languages' => [],
        'site_locales' => [],
        'asset_fields' => [],
        'environments' => [],
        'default_environment' => null,
        'visual_editor' => true,
        'search_driver' => 'mysql',
        'slug_strategy' => 'prepend_translations',
        'filter_hidden_blocks' => false,
        'content_sorting' => false,
        'serial_gaps' => 'preserve',
        'onboarding_dismissed_at' => null,
        'sitemap' => [
            'types' => [],
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function validationRules(bool $partial = false): array
    {
        $sometimes = $partial ? ['sometimes'] : [];
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'region' => [
                ...$sometimes,
                'string',
                'max:20',
            ],
            'default_block' => [
                ...$sometimes,
                'nullable',
                'string',
            ],
            'default_language' => [
                ...$sometimes,
                'string',
                'min:2',
                'max:10',
            ],
            'i18n_mode' => [
                ...$sometimes,
                'string',
                Rule::in(['overlay', 'independent']),
            ],
            'languages' => [
                ...$sometimes,
                'array',
            ],
            'languages.*.code' => [
                ...$required,
                'string',
                'min:2',
                'max:10',
            ],
            'languages.*.name' => [
                ...$required,
                'string',
                'max:100',
            ],
            'languages.*.fallback_language' => [
                'nullable',
                'string',
                'min:2',
                'max:10',
            ],
            'languages.*.hidden' => [
                'nullable',
                'boolean',
            ],
            'site_locales' => [
                ...$sometimes,
                'array',
            ],
            'site_locales.*.segment' => [
                ...$required,
                'string',
                'max:64',
                'distinct:ignore_case',
            ],
            'site_locales.*.language' => [
                ...$required,
                'string',
                'min:2',
                'max:10',
            ],
            'site_locales.*.name' => [
                'nullable',
                'string',
                'max:100',
            ],
            'asset_fields' => [
                ...$sometimes,
                'array',
            ],
            'asset_fields.*.key' => [
                ...$required,
                'string',
                'max:100',
            ],
            'asset_fields.*.label' => [
                ...$required,
                'string',
                'max:100',
            ],
            'asset_fields.*.required' => [
                ...$required,
                'boolean',
            ],
            'environments' => [
                ...$sometimes,
                'array',
            ],
            'environments.*.key' => [
                ...$required,
                'string',
                'max:100',
            ],
            'environments.*.label' => [
                ...$required,
                'string',
                'max:100',
            ],
            'default_environment' => [
                ...$sometimes,
                'nullable',
                'string',
                'max:100',
            ],
            'visual_editor' => [
                ...$sometimes,
                'boolean',
            ],
            'search_driver' => [
                ...$sometimes,
                'string',
                'max:50',
            ],
            'slug_strategy' => [
                ...$sometimes,
                'string',
                Rule::in(['prepend_translations', 'always_prepend', 'never']),
            ],
            'filter_hidden_blocks' => [
                ...$sometimes,
                'boolean',
            ],
            'content_sorting' => [
                ...$sometimes,
                'boolean',
            ],
            'serial_gaps' => [
                ...$sometimes,
                'string',
                Rule::in(['preserve', 'reuse']),
            ],
            'onboarding_dismissed_at' => [
                ...$sometimes,
                'nullable',
                'date',
            ],
            'ai' => [
                ...$sometimes,
                'array',
            ],
            'ai.enabled' => [
                'nullable',
                'boolean',
            ],
            'ai.model' => [
                'nullable',
                'string',
            ],
            'ai.favourites' => [
                'nullable',
                'array',
            ],
            'ai.favourites.*' => [
                'string',
            ],
            'sitemap' => [
                ...$sometimes,
                'array',
            ],
            'sitemap.types' => [
                'nullable',
                'array',
            ],
            'sitemap.types.*.block' => [
                ...$required,
                'string',
                'max:100',
                'distinct:ignore_case',
            ],
            'sitemap.types.*.path' => [
                ...$required,
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function schemaMetadata(): array
    {
        return [
            'region' => [
                'description' => 'Infrastructure region used for the space.',
                'example' => 'eu',
            ],
            'default_block' => [
                'description' => 'Default block identifier used when creating new content.',
                'nullable' => true,
            ],
            'default_language' => [
                'description' => 'Default language ISO code for the space.',
                'example' => 'en',
            ],
            'i18n_mode' => [
                'description' => 'Internationalization mode for content in this space.',
                'enumDescriptions' => [
                    'overlay' => 'Translations can inherit values from the default language.',
                    'independent' => 'Translations are maintained independently.',
                ],
            ],
            'languages' => [
                'description' => 'Additional languages enabled for the space.',
            ],
            'languages.*.code' => [
                'description' => 'Language ISO code.',
                'example' => 'de',
            ],
            'languages.*.name' => [
                'description' => 'Human-readable language name.',
                'example' => 'German',
            ],
            'languages.*.fallback_language' => [
                'description' => 'Fallback language ISO code used when a translation is missing.',
                'nullable' => true,
                'example' => 'en',
            ],
            'languages.*.hidden' => [
                'description' => 'Whether the language should be hidden in UI selectors.',
            ],
            'site_locales' => [
                'description' => 'Mappings of URL path segments to CMS languages, decoupling site URLs '
                    .'from content languages. One language may serve several segments (e.g. "de" under '
                    .'"at-de", "ch-de" and "de-de"). When empty, the slug_strategy applies.',
            ],
            'site_locales.*.segment' => [
                'description' => 'URL path segment (without slashes) the locale is served under.',
                'example' => 'at-de',
            ],
            'site_locales.*.language' => [
                'description' => 'CMS language ISO code rendered for this segment.',
                'example' => 'de',
            ],
            'site_locales.*.name' => [
                'description' => 'Optional display name for the locale.',
                'nullable' => true,
                'example' => 'Austria (DE)',
            ],
            'asset_fields' => [
                'description' => 'Configured metadata fields available for assets in the space.',
            ],
            'asset_fields.*.key' => [
                'description' => 'Unique field key.',
                'example' => 'alt',
            ],
            'asset_fields.*.label' => [
                'description' => 'Display label for the asset field.',
                'example' => 'Alt Text',
            ],
            'asset_fields.*.required' => [
                'description' => 'Whether the field is required.',
            ],
            'environments' => [
                'description' => 'Configured deployment or delivery environments for the space.',
            ],
            'environments.*.key' => [
                'description' => 'Unique environment key.',
                'example' => 'preview',
            ],
            'environments.*.label' => [
                'description' => 'Display label for the environment.',
                'example' => 'Preview',
            ],
            'default_environment' => [
                'description' => 'Name of the default environment used when no user preference is set.',
                'nullable' => true,
                'example' => 'production',
            ],
            'visual_editor' => [
                'description' => 'Whether the visual editor is enabled for the space.',
            ],
            'search_driver' => [
                'description' => 'Search backend driver used by the space.',
                'example' => 'mysql',
            ],
            'slug_strategy' => [
                'description' => 'Controls if localized URLs should include a locale prefix.',
                'enumDescriptions' => [
                    'prepend_translations' => 'Only translated, non-default languages are prefixed.',
                    'always_prepend' => 'All languages are prefixed.',
                    'never' => 'No language prefix is added.',
                ],
            ],
            'filter_hidden_blocks' => [
                'description' => 'Whether hidden blocks should be filtered from resolved API content responses.',
            ],
            'content_sorting' => [
                'description' => 'Whether manual drag-and-drop ordering of content is enabled for the space. '
                    .'When disabled, content is ordered alphabetically by name.',
            ],
            'serial_gaps' => [
                'description' => 'What happens to the number of a deleted entry in auto-generated serial fields.',
                'example' => 'preserve',
                'enumDescriptions' => [
                    'preserve' => 'The number is never handed out again; deleting an entry leaves a permanent gap.',
                    'reuse' => 'The number returns to the pool and is given to the next entry. A restored entry '
                        .'is renumbered when its original number was taken in the meantime.',
                ],
            ],
            'onboarding_dismissed_at' => [
                'description' => 'When the onboarding guide was dismissed for this space. '
                    .'Null while the guide is still shown in the navigation.',
                'nullable' => true,
                'example' => '2026-07-15T10:00:00+00:00',
            ],
            'ai' => [
                'description' => 'AI-related defaults and preferences for the space.',
            ],
            'ai.enabled' => [
                'description' => 'Whether AI features are enabled for the space.',
            ],
            'ai.model' => [
                'description' => 'Default AI model identifier for the space.',
                'nullable' => true,
            ],
            'ai.favourites' => [
                'description' => 'List of favourite AI model identifiers available in the UI.',
            ],
            'ai.favourites.*' => [
                'description' => 'AI model identifier.',
            ],
            'sitemap' => [
                'description' => 'Sitemap extraction rules for public Data API endpoints.',
            ],
            'sitemap.types' => [
                'description' => 'Mappings of content block slugs to the meta object path used for sitemap extraction.',
            ],
            'sitemap.types.*.block' => [
                'description' => 'Block slug to include in sitemap responses.',
                'example' => 'page',
            ],
            'sitemap.types.*.path' => [
                'description' => 'Dot path inside the effective content payload where the sitemap meta object lives.',
                'example' => 'meta',
            ],
        ];
    }

    public function getDefaultEnvironment(): ?array
    {
        $name = $this->attributes['default_environment'] ?? null;

        if (! $name) {
            return null;
        }

        foreach ($this->attributes['environments'] ?? [] as $environment) {
            if (($environment['name'] ?? null) === $name) {
                return $environment;
            }
        }

        return null;
    }

    public function shouldFilterHiddenBlocks(): bool
    {
        return (bool) ($this->attributes['filter_hidden_blocks'] ?? false);
    }

    public function isContentSortingEnabled(): bool
    {
        return (bool) ($this->attributes['content_sorting'] ?? false);
    }

    /**
     * How serial numbers behave when an entry is deleted: `preserve` burns the
     * number, `reuse` returns it to the pool.
     */
    public function getSerialGapStrategy(): string
    {
        return ($this->attributes['serial_gaps'] ?? 'preserve') === 'reuse' ? 'reuse' : 'preserve';
    }

    /**
     * @return array<int, array{block: string, path: string}>
     */
    public function getSitemapTypes(): array
    {
        return array_values(array_filter(
            $this->attributes['sitemap']['types'] ?? [],
            fn (mixed $type): bool => is_array($type)
                && filled($type['block'] ?? null)
                && filled($type['path'] ?? null),
        ));
    }

    public function shouldPrependLocale(string $languageIso): bool
    {
        $strategy = $this->attributes['slug_strategy'] ?? 'prepend_translations';
        $defaultLanguage = $this->getDefaultLanguage();

        return match ($strategy) {
            'always_prepend' => true,
            'prepend_translations' => $languageIso !== $defaultLanguage,
            'never' => false,
            default => $languageIso !== $defaultLanguage
        };
    }

    /**
     * @return array<int, array{segment: string, language: string, name: ?string}>
     */
    public function getSiteLocales(): array
    {
        return array_values(array_filter(
            $this->attributes['site_locales'] ?? [],
            fn (mixed $locale): bool => \is_array($locale)
                && filled($locale['segment'] ?? null)
                && filled($locale['language'] ?? null),
        ));
    }

    /**
     * All URL path segments a language is served under (without slashes).
     * Empty when no site locales map to the language.
     *
     * @return array<int, string>
     */
    public function getSegmentsForLanguage(string $languageIso): array
    {
        $segments = [];

        foreach ($this->getSiteLocales() as $locale) {
            if ($locale['language'] === $languageIso) {
                $segments[] = trim((string) $locale['segment'], '/');
            }
        }

        return array_values(array_unique(array_filter($segments)));
    }

    /**
     * The CMS language a URL segment renders, or null for unknown segments.
     */
    public function getLanguageForSegment(string $segment): ?string
    {
        $segment = trim($segment, '/');

        foreach ($this->getSiteLocales() as $locale) {
            if (trim((string) $locale['segment'], '/') === $segment) {
                return $locale['language'];
            }
        }

        return null;
    }

    /**
     * Resolve the default URL path segment for a language (without slashes).
     *
     * The first matching site locale wins; otherwise the `slug_strategy`
     * decides whether the raw language code is used. Returns an empty string
     * when no segment should be prepended. Backwards compatible: without site
     * locales this returns exactly the `shouldPrependLocale`-driven behaviour.
     */
    public function getLocaleSegment(string $languageIso): string
    {
        $segments = $this->getSegmentsForLanguage($languageIso);
        if ($segments !== []) {
            return $segments[0];
        }

        return $this->shouldPrependLocale($languageIso) ? $languageIso : '';
    }

    public function getEnabledLanguages(): array
    {
        return [
            $this->getDefaultLanguage(),
            ...array_values(array_map(fn ($language): string => $language['code'], $this->attributes['languages'] ?? [])),
        ];
    }

    public function getDefaultLanguage(): string
    {
        return $this->attributes['default_language'] ?? 'en';
    }

    public function getI18nMode(): string
    {
        return $this->attributes['i18n_mode'] ?? 'overlay';
    }

    public function getLanguageConfig(string $languageIso): ?array
    {
        if ($languageIso === $this->getDefaultLanguage()) {
            return null;
        }

        foreach ($this->attributes['languages'] ?? [] as $language) {
            if (($language['code'] ?? null) === $languageIso) {
                return $language;
            }
        }

        return null;
    }

    public function getFallbackLanguage(string $languageIso): ?string
    {
        if ($languageIso === $this->getDefaultLanguage()) {
            return null;
        }

        return $this->getLanguageConfig($languageIso)['fallback_language'] ?? $this->getDefaultLanguage();
    }

    public function getLanguageLabel(string $languageIso): string
    {
        return $this->getLanguageConfig($languageIso)['name'] ?? strtoupper($languageIso);
    }

    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes, SerializesCastableAttributes
        {
            public function get($model, string $key, $value, array $attributes)
            {
                return SpaceSettings::make($this->normalizeSettings($value));
            }

            public function set($model, string $key, mixed $value, array $attributes)
            {
                $settings = $this->normalizeSettings($value);
                $originalSettings = $this->normalizeSettings($attributes[$key] ?? null);

                if ($settings === $originalSettings) {
                    return $attributes[$key] ?? null;
                }

                return json_encode($settings);
            }

            public function serialize($model, string $key, $value, array $attributes)
            {
                return json_encode($this->normalizeSettings($value));
            }

            private function normalizeSettings(mixed $value): array
            {
                if (\is_string($value)) {
                    $decoded = json_decode($value, true);
                    $value = \is_array($decoded) ? $decoded : [];
                } elseif (! \is_array($value)) {
                    $value = $value instanceof SpaceSettings ? $value->toArray() : [];
                }

                return app(SpaceI18nSettingsService::class)->normalize($value);
            }
        };
    }
}
