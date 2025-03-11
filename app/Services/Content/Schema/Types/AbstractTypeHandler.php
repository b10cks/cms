<?php

namespace App\Services\Content\Schema\Types;

use App\Services\Content\Schema\SchemaField;
use Illuminate\Support\Facades\Validator;

abstract class AbstractTypeHandler implements TypeHandlerInterface
{
    /**
     * Get the type name this handler manages
     */
    abstract public function getType(): string;

    /**
     * Validate a value against this type's rules
     */
    public function validate(SchemaField $field, $value, array $context = []): array
    {
        $rules = $this->getValidationRules($field);

        // If field is not required and value is empty, return no errors
        if (!$field->isRequired() && $this->isEmpty($value)) {
            return [];
        }

        $validator = Validator::make(['value' => $value], ['value' => $rules]);

        if ($validator->fails()) {
            return $validator->errors()->get('value');
        }

        return [];
    }

    /**
     * Prepare a value for storage
     */
    public function prepare(SchemaField $field, $value): mixed
    {
        return $value;
    }

    /**
     * Cast a value from storage
     */
    public function cast(SchemaField $field, $value): mixed
    {
        return $value;
    }

    /**
     * Get frontend validation rules
     */
    public function getFrontendRules(SchemaField $field): array
    {
        $rules = [];

        if ($field->isRequired()) {
            $rules['required'] = true;
        }

        return $rules;
    }

    /**
     * Check if a dependency condition is met
     */
    public function evaluateDependency(SchemaField $field, array $condition, array $values): bool
    {
        // Default implementation - should be overridden by specific types
        return true;
    }

    /**
     * Get validation rules for this field
     */
    protected function getValidationRules(SchemaField $field): array
    {
        $rules = [];

        if ($field->isRequired()) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        // Add custom validation rules from field attributes
        if ($validationRules = $field->getAttribute('validation')) {
            if (is_array($validationRules)) {
                $rules = array_merge($rules, $validationRules);
            } elseif (is_string($validationRules)) {
                $rules = array_merge($rules, explode('|', $validationRules));
            }
        }

        return $rules;
    }

    /**
     * Check if a value is empty
     */
    protected function isEmpty($value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
