<?php

namespace App\DTOs\ContentData;

/**
 * A single translatable string within a content document, addressed by a stable,
 * reorder-proof identifier (block-item id chain + field key, plus table row/column
 * or meta sub-key where applicable).
 */
class TranslationUnit
{
    /**
     * @param  array<int, string|int>  $path  Concrete path into the content tree this unit was extracted from.
     * @param  array<string, string>  $targets  languageIso => translated value.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $fieldKey,
        public readonly string $type,
        public readonly string $label,
        public readonly string $note,
        public readonly array $path,
        public readonly string $source,
        public readonly array $targets = [],
    ) {
    }
}
