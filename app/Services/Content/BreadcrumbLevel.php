<?php

namespace App\Services\Content;

use App\Models\Space\Content;

/**
 * One level of a breadcrumb trail: the content row that was picked for the
 * requested language, plus everything about *why* it was picked that the
 * resource cannot derive from the row alone.
 */
class BreadcrumbLevel
{
    public function __construct(
        /** The row actually rendered — already the requested language's row where one exists. */
        public readonly Content $row,
        public readonly string $requestedLanguage,
        /** Language of {@see $row}; differs from the requested one when a fallback was used. */
        public readonly string $resolvedLanguage,
        /** Depth in the content tree, 0 = root. Not the index in the trail: unpublished ancestors are dropped. */
        public readonly int $depth,
        public readonly bool $isRoot,
        public readonly bool $isCurrent,
        /** Delivery path with the requested language's locale segment applied. */
        public readonly string $path,
        /**
         * Published sibling translations, only when requested.
         *
         * @var array<int, array{language_iso: string, name: string|null, full_slug: string, path: string}>|null
         */
        public readonly ?array $translations = null,
        /** Overlay-resolved payload, only when requested. */
        public readonly ?ResolvedContent $resolved = null,
    ) {}

    public function isFallback(): bool
    {
        return $this->resolvedLanguage !== $this->requestedLanguage;
    }
}
