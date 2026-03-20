<?php

namespace App\Services\Content;

use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Support\Collection;

class ResolvedContent
{
    public function __construct(
        public readonly Content $canonicalContent,
        public readonly Collection $familyContents,
        public readonly string $requestedLanguage,
        public readonly string $resolvedLanguage,
        public readonly string $effectiveMode,
        public readonly ?Content $resolvedRow,
        public readonly ?Content $targetContent,
        public readonly ?ContentVersion $targetVersion,
        public readonly ?Content $fallbackContent,
        public readonly ?ContentVersion $fallbackVersion,
        public readonly array $effectiveContent,
        public readonly Collection $effectiveAssets,
        public readonly Collection $effectiveLinks,
        public readonly Collection $effectiveRelations,
    ) {
    }
}
