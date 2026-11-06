<?php

namespace App\Services\Ai\Translation;

use App\Services\ContentData\ContentTranslationExtractor;

/**
 * The translatable text of a content document, as units with the stable IDs
 * of the translation export (`title`, `body.<block id>.text`, `seo.title`,
 * `prices.rows.<row id>.label`). A unit ID maps to the field that stores it:
 * meta and table units live inside one field value.
 */
class TranslationUnits
{
    /** Field types whose units are prose. Numbers and dates are localized by people, not translated. */
    public const PROSE_TYPES = ['text', 'textarea', 'markdown', 'richtext', 'meta', 'table'];

    /** Fields stored as one value that holds several units. */
    public const COMPOSITE_TYPES = ['meta', 'table'];

    public function __construct(private readonly ContentTranslationExtractor $extractor) {}

    /**
     * @param  array<string, mixed>  $tree
     * @param  array<string, mixed>  $schema  the canonical block schema
     * @return array<string, array{fieldKey: string, type: string, label: string, note: string, path: array<int, string|int>, value: string, translatable: bool}>
     */
    public function collect(array $tree, array $schema): array
    {
        return array_filter(
            $this->extractor->collectUnits($tree, $schema),
            fn (array $unit) => \in_array($unit['type'], self::PROSE_TYPES, true),
        );
    }

    /**
     * Unit ID => text, for comparing documents.
     *
     * @param  array<string, mixed>  $tree
     * @param  array<string, mixed>  $schema
     * @return array<string, string>
     */
    public function texts(array $tree, array $schema): array
    {
        return array_map(fn (array $unit) => $unit['value'], $this->collect($tree, $schema));
    }

    /** The field path (change set path) a unit is stored in. */
    public static function fieldPath(string $unitId, string $type): string
    {
        $segments = explode('.', $unitId);

        return match ($type) {
            'meta' => implode('.', \array_slice($segments, 0, -1)),
            // <field>.header.<column> or <field>.rows.<row id>.<column>
            'table' => implode('.', \array_slice($segments, 0, ($segments[\count($segments) - 2] ?? null) === 'header' ? -2 : -3)),
            default => $unitId,
        };
    }

    public static function isBlank(string $text): bool
    {
        return trim(strip_tags($text)) === '' && ! preg_match('/<(img|hr|iframe|video)\b/i', $text);
    }
}
