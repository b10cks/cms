<?php

namespace App\Models\User;

use App\Models\Settings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;

class UserSettings extends Settings
{
    protected array $defaults = [
        'languageIso' => 'en',
        'extendedSidebar' => true,
    ];

    public static function validationRules(bool $partial = false): array
    {
        return [
            'languageIso' => [
                ...($partial ? ['sometimes'] : []),
                'nullable',
                'string',
                'min:2',
                'max:5',
            ],
            'extendedSidebar' => [
                ...($partial ? ['sometimes'] : []),
                'boolean',
            ],
        ];
    }

    public static function schemaMetadata(): array
    {
        return [
            'languageIso' => [
                'description' => 'Preferred UI language as ISO language code.',
                'example' => 'en',
            ],
            'extendedSidebar' => [
                'description' => 'Whether the management sidebar should be shown in its extended state.',
                'example' => true,
            ],
        ];
    }

    public static function castUsing(array $arguments)
    {
        return new class implements CastsAttributes, SerializesCastableAttributes {
            public function get($model, string $key, $value, array $attributes)
            {
                return UserSettings::make($value ? json_decode($value, true) : []);
            }

            public function set($model, string $key, $value, array $attributes)
            {
                return json_encode(\is_array($value) ? $value : $value?->toArray() ?? []);
            }

            public function serialize($model, string $key, $value, array $attributes)
            {
                return json_encode(\is_array($value) ? $value : $value?->toArray() ?? []);
            }
        };
    }
}
