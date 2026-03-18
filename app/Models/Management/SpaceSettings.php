<?php

namespace App\Models\Management;

use App\Models\Settings;
use App\Services\Space\SpaceI18nSettingsService;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;

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
        'visual_editor' => true,
        'search_driver' => 'mysql',
        'slug_strategy' => 'prepend_translations',
    ];

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
