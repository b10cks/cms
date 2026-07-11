<?php

namespace App\Models\Space;

use App\Models\Settings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Validation\Rule;

class ContentSettings extends Settings
{
    public const array CHILD_SORT_FIELDS = ['name', 'published_at', 'created_at', 'updated_at'];

    /**
     * Matches `content.{field}` sort keys targeting a first-level key of the
     * content payload. The strict charset keeps the field safe to embed in
     * JSON path expressions.
     */
    public const string CHILD_CONTENT_SORT_PATTERN = '/^content\.([a-zA-Z0-9_]+)$/';

    protected array $defaults = [
        'disablePreview' => false,
        'i18n_mode_override' => 'inherit',
        'restrict_child_blocks' => false,
        'child_block_whitelist' => [],
        'child_tag_whitelist' => [],
        'default_child_block' => null,
        'child_sort_by' => 'inherit',
        'child_sort_direction' => 'asc',
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
            'child_sort_by' => [
                $partial ? 'sometimes' : 'nullable',
                'string',
                'max:120',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (\in_array($value, ['inherit', 'manual', ...self::CHILD_SORT_FIELDS], true)) {
                        return;
                    }

                    if (\is_string($value) && preg_match(self::CHILD_CONTENT_SORT_PATTERN, $value)) {
                        return;
                    }

                    $fail('The selected child sorting field is invalid.');
                },
            ],
            'child_sort_direction' => [
                $partial ? 'sometimes' : 'nullable',
                'string',
                Rule::in(['asc', 'desc']),
            ],
        ];
    }

    /**
     * The content column configured for ordering children of this entry:
     * a sortable attribute, `position` for forced manual ordering, or null
     * when inheriting the space-level behaviour.
     */
    public function getChildSortColumn(): ?string
    {
        $sortBy = $this->attributes['child_sort_by'] ?? 'inherit';

        if ($sortBy === 'manual') {
            return 'position';
        }

        return \in_array($sortBy, self::CHILD_SORT_FIELDS, true) ? $sortBy : null;
    }

    /**
     * The first-level content payload key configured via `content.{field}`,
     * or null when children are not sorted by a content field.
     */
    public function getChildContentSortField(): ?string
    {
        $sortBy = $this->attributes['child_sort_by'] ?? '';

        if (\is_string($sortBy) && preg_match(self::CHILD_CONTENT_SORT_PATTERN, $sortBy, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function getChildSortDirection(): string
    {
        return ($this->attributes['child_sort_direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
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
            'child_sort_by' => [
                'description' => 'How direct children of this content entry are ordered. Besides the '
                    .'listed values, `content.{field}` orders children by a first-level key of their '
                    .'content payload (e.g. `content.publishDate`).',
                'example' => 'published_at',
                'enumDescriptions' => [
                    'inherit' => 'Use the space-level content sorting behaviour.',
                    'manual' => 'Order children by their manually assigned position.',
                    'name' => 'Order children alphabetically by name.',
                    'published_at' => 'Order children by publication date.',
                    'created_at' => 'Order children by creation date.',
                    'updated_at' => 'Order children by last update date.',
                ],
            ],
            'child_sort_direction' => [
                'description' => 'Sort direction applied when children are ordered by an attribute.',
                'example' => 'desc',
                'enumDescriptions' => [
                    'asc' => 'Ascending order (oldest / A first).',
                    'desc' => 'Descending order (newest / Z first).',
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
