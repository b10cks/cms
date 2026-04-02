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
        'restrict_child_blocks' => false,
        'child_block_whitelist' => [],
        'child_tag_whitelist' => [],
        'default_child_block' => null,
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
            'restrict_child_blocks' => [$partial ? 'sometimes' : 'nullable', 'boolean'],
            'child_block_whitelist' => [$partial ? 'sometimes' : 'nullable', 'array'],
            'child_block_whitelist.*' => ['string'],
            'child_tag_whitelist' => [$partial ? 'sometimes' : 'nullable', 'array'],
            'child_tag_whitelist.*' => ['string'],
            'default_child_block' => [$partial ? 'sometimes' : 'nullable', 'string'],
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
            'restrict_child_blocks' => [
                'description' => 'Whether child content types should be restricted for this content family.',
                'example' => false,
            ],
            'child_block_whitelist' => [
                'description' => 'Allowed child content type slugs when child restrictions are active.',
            ],
            'child_tag_whitelist' => [
                'description' => 'Allowed child content type tags when child restrictions are active.',
            ],
            'default_child_block' => [
                'description' => 'Default child content block identifier for new children in this family.',
                'nullable' => true,
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
