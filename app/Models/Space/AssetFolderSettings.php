<?php

namespace App\Models\Space;

use App\Models\Settings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;

class AssetFolderSettings extends Settings
{
    protected array $defaults = [
        'field_overrides' => [],
        'additional_fields' => [],
    ];

    public static function validationRules(bool $partial = false): array
    {
        $topLevelArrayRule = $partial ? ['sometimes', 'nullable', 'array'] : ['nullable', 'array'];

        return [
            'field_overrides' => $topLevelArrayRule,
            'field_overrides.*.key' => ['required', 'string', 'max:100'],
            'field_overrides.*.enabled' => ['nullable', 'boolean'],
            'field_overrides.*.required' => ['nullable', 'boolean'],

            'additional_fields' => $topLevelArrayRule,
            'additional_fields.*.key' => ['required', 'string', 'max:100'],
            'additional_fields.*.label' => ['required', 'string', 'max:100'],
            'additional_fields.*.required' => ['required', 'boolean'],
        ];
    }

    public static function schemaMetadata(): array
    {
        return [
            'field_overrides' => [
                'description' => 'Overrides inherited asset field configuration for this folder.',
                'example' => [
                    [
                        'key' => 'alt',
                        'enabled' => true,
                        'required' => true,
                    ],
                ],
            ],
            'field_overrides.*.key' => [
                'description' => 'The asset field key to override.',
                'example' => 'alt',
            ],
            'field_overrides.*.enabled' => [
                'description' => 'Whether the inherited field is enabled in this folder.',
                'example' => true,
            ],
            'field_overrides.*.required' => [
                'description' => 'Whether the inherited field must be filled for assets in this folder.',
                'example' => false,
            ],
            'additional_fields' => [
                'description' => 'Additional custom asset metadata fields available only within this folder.',
                'example' => [
                    [
                        'key' => 'photographer',
                        'label' => 'Photographer',
                        'required' => false,
                    ],
                ],
            ],
            'additional_fields.*.key' => [
                'description' => 'Unique machine-readable key of the additional field.',
                'example' => 'photographer',
            ],
            'additional_fields.*.label' => [
                'description' => 'Human-readable label shown for the additional field.',
                'example' => 'Photographer',
            ],
            'additional_fields.*.required' => [
                'description' => 'Whether the additional field is required.',
                'example' => false,
            ],
        ];
    }

    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes, SerializesCastableAttributes {
            public function get($model, string $key, $value, array $attributes)
            {
                return AssetFolderSettings::make($value ? json_decode($value, true) : []);
            }

            public function set($model, string $key, mixed $value, array $attributes)
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
