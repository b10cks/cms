<?php

namespace App\Models\Space;

use App\Models\Settings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Validation\Rule;

class ContentSettings extends Settings
{
    protected array $defaults = [
        'disablePreview' => false,
        'i18n_mode_override' => 'inherit',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function validationRules(bool $partial = false): array
    {
        return [
            'disablePreview' => [$partial ? 'sometimes' : 'nullable', 'boolean'],
            'i18n_mode_override' => [
                $partial ? 'sometimes' : 'nullable',
                'string',
                Rule::in(['inherit', 'overlay', 'independent']),
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function schemaMetadata(): array
    {
        return [
            'disablePreview' => [
                'description' => 'Disable preview rendering for this content entry.',
                'example' => false,
            ],
            'i18n_mode_override' => [
                'description' => 'Override the space i18n mode for this content entry.',
                'example' => 'inherit',
                'enumDescriptions' => [
                    'inherit' => 'Use the i18n mode configured on the space.',
                    'overlay' => 'Use translated fields as overlays on top of the default language content.',
                    'independent' => 'Treat translations as fully independent content entries.',
                ],
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function castUsing(array $arguments)
    {
        return new class implements CastsAttributes, SerializesCastableAttributes {
            public function get($model, string $key, $value, array $attributes)
            {
                return ContentSettings::make($value ? json_decode($value, true) : []);
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
