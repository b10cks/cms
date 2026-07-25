<?php

namespace App\Models\Space;

use App\Models\Settings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;

/**
 * Per-block configuration that is not part of the field schema.
 *
 * The `settings` column has existed on `blocks` since the space tables were
 * created but was never fillable or cast, so writes to it were silently
 * dropped. This class is where it comes to life.
 */
class BlockSettings extends Settings
{
    public const string DEFAULT_SLUG_PATTERN = '{field:name}';

    protected array $defaults = [
        'slug_pattern' => null,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function validationRules(bool $partial = false): array
    {
        return [
            'slug_pattern' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The pattern new entries of this block seed their slug from.
     *
     * Null means the historic behaviour — the slug follows the entry name — and
     * is what every block that predates this setting reports.
     */
    public function getSlugPattern(): string
    {
        $pattern = $this->attributes['slug_pattern'] ?? null;

        return is_string($pattern) && trim($pattern) !== ''
            ? $pattern
            : self::DEFAULT_SLUG_PATTERN;
    }

    public function hasCustomSlugPattern(): bool
    {
        return $this->getSlugPattern() !== self::DEFAULT_SLUG_PATTERN;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function schemaMetadata(): array
    {
        return [
            'slug_pattern' => [
                'description' => 'Token pattern new entries of this block seed their slug from, e.g. '
                    .'`{field:number}-{field:name}`. Null keeps the default, which is the entry name.',
                'nullable' => true,
                'example' => '{field:number}-{field:name}',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function castUsing(array $arguments)
    {
        return new class implements CastsAttributes, SerializesCastableAttributes
        {
            public function get($model, string $key, $value, array $attributes)
            {
                return BlockSettings::make($value ? json_decode($value, true) : []);
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
