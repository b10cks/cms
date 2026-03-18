<?php

namespace App\Services\Content\Schema;

class ContentSchemaValidationResult
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        public readonly array $content,
        public readonly ContentSchemaTree $tree,
        public readonly array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
