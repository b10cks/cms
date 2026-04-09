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
        'asset_fields' => [],
        'environments' => [],
        'default_environment' => null,
        'visual_editor' => true,
        'search_driver' => 'mysql',
        'slug_strategy' => 'prepend_translations',
        'filter_hidden_blocks' => false,
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
                $settings = $value ? json_decode($value, true) : [];

                return SpaceSettings::make(app(SpaceI18nSettingsService::class)->normalize($settings));
            }

            public function set($model, string $key, mixed $value, array $attributes)
            {
                $settings = \is_array($value) ? $value : $value->toArray();

                return json_encode(app(SpaceI18nSettingsService::class)->normalize($settings));
            }

            public function serialize($model, string $key, $value, array $attributes)
            {
                $settings = \is_array($value) ? $value : $value->toArray();

                return json_encode(app(SpaceI18nSettingsService::class)->normalize($settings));
            }
        };
    }
}
