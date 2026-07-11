<?php

namespace App\DTOs\ContentData;

/**
 * All translatable units of a single content family (one canonical content plus its
 * per-language translation rows), ready to be serialized by a format driver.
 */
class TranslationDocument
{
    /**
     * @param  array<int, string>  $languages  Target language codes (excludes the source language).
     * @param  array<int, TranslationUnit>  $units
     */
    public function __construct(
        public readonly string $contentId,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $sourceLanguage,
        public readonly array $languages,
        public readonly array $units,
    ) {
    }
}
