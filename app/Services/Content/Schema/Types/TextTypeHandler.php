<?php

namespace App\Services\Content\Schema\Types;

use App\Services\Content\Schema\SchemaField;

class TextTypeHandler extends AbstractTypeHandler
{
    public function getType(): string
    {
        return 'text';
    }

    protected function getValidationRules(SchemaField $field): array
    {
        $rules = parent::getValidationRules($field);

        // Add max length rule if specified
        if ($maxLength = $field->getAttribute('maximum')) {
            $rules[] = "max:{$maxLength}";
        }

        return $rules;
    }

    public function getFrontendRules(SchemaField $field): array
    {
        $rules = parent::getFrontendRules($field);

        if ($maxLength = $field->getAttribute('maximum')) {
            $rules['maxLength'] = (int) $maxLength;
        }

        return $rules;
    }
}
