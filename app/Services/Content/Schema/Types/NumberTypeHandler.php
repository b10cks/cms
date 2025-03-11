<?php

namespace App\Services\Content\Schema\Types;

use App\Services\Content\Schema\SchemaField;

class NumberTypeHandler extends AbstractTypeHandler
{
    public function getType(): string
    {
        return 'number';
    }

    protected function getValidationRules(SchemaField $field): array
    {
        $rules = parent::getValidationRules($field);
        $rules[] = 'numeric';

        // Add min/max rules if specified
        if ($min = $field->getAttribute('minimum')) {
            $rules[] = "min:{$min}";
        }

        if ($max = $field->getAttribute('maximum')) {
            $rules[] = "max:{$max}";
        }

        // Handle decimals
        if ($decimals = $field->getAttribute('decimals')) {
            if ($decimals == 0) {
                $rules[] = 'integer';
            } else {
                $rules[] = "decimal:0,{$decimals}";
            }
        }

        return $rules;
    }

    public function getFrontendRules(SchemaField $field): array
    {
        $rules = parent::getFrontendRules($field);

        $rules['type'] = 'number';

        if ($min = $field->getAttribute('minimum')) {
            $rules['min'] = (float) $min;
        }

        if ($max = $field->getAttribute('maximum')) {
            $rules['max'] = (float) $max;
        }

        if ($step = $field->getAttribute('step_size')) {
            $rules['step'] = (float) $step;
        }

        return $rules;
    }

    public function prepare(SchemaField $field, $value): mixed
    {
        // Convert to proper type based on decimals
        if ($value === null || $value === '') {
            return null;
        }

        $decimals = $field->getAttribute('decimals', 0);

        if ($decimals == 0) {
            return (int) $value;
        }

        return (float) $value;
    }
}
