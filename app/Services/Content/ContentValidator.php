<?php

namespace App\Services\Content;

use App\Services\Content\Schema\BlockSchema;
use App\Services\Content\Schema\TypeRegistry;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ContentValidator
{
    protected TypeRegistry $typeRegistry;

    public function __construct(TypeRegistry $typeRegistry)
    {
        $this->typeRegistry = $typeRegistry;
    }

    /**
     * Validate content against a schema
     */
    public function validate(BlockSchema $schema, array &$content): bool
    {
        $errors = [];

        // Check each field in the schema
        foreach ($schema->getFields() as $field) {
            $key = $field->getKey();
            $type = $field->getType();

            // Skip if no handler for this type
            if (!$this->typeRegistry->hasHandler($type)) {
                continue;
            }

            $handler = $this->typeRegistry->getHandler($type);
            $value = Arr::get($content, $key);

            // Check dependencies
            if (!$this->checkDependencies($field->getDependencies(), $content)) {
                continue; // Skip validation if dependencies aren't met
            }

            // Validate with type handler
            $fieldErrors = $handler->validate($field, $value, $content);

            if (!empty($fieldErrors)) {
                $errors[$key] = $fieldErrors;
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return true;
    }

    /**
     * Check if dependencies are met
     */
    protected function checkDependencies(array $dependencies, array $content): bool
    {
        if (empty($dependencies)) {
            return true;
        }

        foreach ($dependencies as $dependency) {
            $field = $dependency['field'] ?? null;
            $value = $dependency['value'] ?? null;
            $operator = $dependency['operator'] ?? '=';

            if ($field === null) {
                continue;
            }

            $fieldValue = Arr::get($content, $field);

            // Evaluate based on operator
            switch ($operator) {
                case '=':
                    if ($fieldValue != $value) {
                        return false;
                    }
                    break;
                case '!=':
                    if ($fieldValue == $value) {
                        return false;
                    }
                    break;
                case '>':
                    if ($fieldValue <= $value) {
                        return false;
                    }
                    break;
                case '<':
                    if ($fieldValue >= $value) {
                        return false;
                    }
                    break;
                case 'in':
                    if (!in_array($fieldValue, (array)$value)) {
                        return false;
                    }
                    break;
                case 'not_in':
                    if (in_array($fieldValue, (array)$value)) {
                        return false;
                    }
                    break;
                case 'empty':
                    if (!empty($fieldValue)) {
                        return false;
                    }
                    break;
                case 'not_empty':
                    if (empty($fieldValue)) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }

    /**
     * Get frontend validation rules for a schema
     */
    public function getFrontendRules(BlockSchema $schema): array
    {
        $rules = [];

        foreach ($schema->getFields() as $key => $field) {
            $type = $field->getType();

            if (!$this->typeRegistry->hasHandler($type)) {
                continue;
            }

            $handler = $this->typeRegistry->getHandler($type);
            $rules[$key] = $handler->getFrontendRules($field);

            // Add dependencies
            if ($dependencies = $field->getDependencies()) {
                $rules[$key]['dependencies'] = $dependencies;
            }
        }

        return $rules;
    }
}
