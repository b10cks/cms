<?php

namespace App\Services\Content\Schema\Types;

use App\Services\Content\Schema\SchemaField;

class BooleanTypeHandler extends AbstractTypeHandler
{
    public function getType(): string
    {
        return 'boolean';
    }

    public function getFrontendRules(SchemaField $field): array
    {
        $rules = parent::getFrontendRules($field);
        $rules['type'] = 'boolean';

        return $rules;
    }

    public function prepare(SchemaField $field, $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (bool) $value;
    }
}
