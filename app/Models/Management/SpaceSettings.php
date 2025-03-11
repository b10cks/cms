<?php

namespace App\Models\Management;

use App\Models\Settings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;

class SpaceSettings extends Settings
{
    protected array $defaults = [
        'region' => 'eu',
        'default_language' => 'en',
        'languages' => [],
        'asset_fields' => [],
        'environments' => [],
        'visual_editor' => true,
        'slug_strategy' => 'prepend_translations',
    ];

    public function shouldPrependLocale(string $languageIso): bool
    {
        $strategy = $this['slug_strategy'] ?? 'prepend_translations';
        $defaultLanguage = $this['default_language'] ?? 'en';

        return match ($strategy) {
            'always_prepend' => true,
            'prepend_translations' => $languageIso !== $defaultLanguage,
            'never' => false,
            default => $languageIso !== $defaultLanguage
        };
    }

    public static function castUsing(array $arguments)
    {
        return new class implements CastsAttributes, SerializesCastableAttributes {
            public function get($model, string $key, $value, array $attributes)
            {
                return SpaceSettings::make($value ? json_decode($value, true) : []);
            }

            public function set($model, string $key, $value, array $attributes)
            {
                return json_encode($value ?? []);
            }

            public function serialize($model, string $key, $value, array $attributes)
            {
                return json_encode($value);
            }
        };
    }
}
