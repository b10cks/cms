<?php

namespace App\Services\Content\Schema\Types;

use App\Services\Content\Schema\SchemaField;

interface TypeHandlerInterface
{
    /**
     * Get the type name this handler manages
     */
    public function getType(): string;

    /**
     * Validate a value against this type's rules
     */
    public function validate(SchemaField $field, $value, array $context = []): array;

    /**
     * Prepare a value for storage
     */
    public function prepare(SchemaField $field, $value): mixed;

    /**
     * Cast a value from storage
     */
    public function cast(SchemaField $field, $value): mixed;

    /**
     * Get frontend validation rules
     */
    public function getFrontendRules(SchemaField $field): array;

    /**
     * Check if a dependency condition is met
     */
    public function evaluateDependency(SchemaField $field, array $condition, array $values): bool;
}
