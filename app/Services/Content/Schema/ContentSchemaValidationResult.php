<?php

namespace App\Services\Content\Schema;

class ContentSchemaValidationResult
{
    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, array<int, string>>  $warnings
     */
    public function __construct(
        public readonly array $content,
        public readonly ContentSchemaTree $tree,
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
