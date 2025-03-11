<?php

namespace App\Services\Content\Schema\Types;

use App\Services\Content\Schema\SchemaField;

class OptionTypeHandler extends AbstractTypeHandler
{
    public function getType(): string
    {
        return 'option';
    }

    protected function getValidationRules(SchemaField $field): array
    {
        $rules = parent::getValidationRules($field);

        if ($options = $this->getOptions($field)) {
            $validValues = collect($options)->pluck('value')->toArray();
            $rules[] = 'in:' . implode(',', $validValues);
        }

        return $rules;
    }

    public function getFrontendRules(SchemaField $field): array
    {
        $rules = parent::getFrontendRules($field);

        $rules['type'] = 'option';
        $rules['options'] = $this->getOptions($field);

        if ($excludeEmptyOption = $field->getAttribute('exclude_empty_option', false)) {
            $rules['excludeEmptyOption'] = true;
        }

        if ($defaultValue = $field->getAttribute('default_value')) {
            $rules['defaultValue'] = $defaultValue;
        }

        return $rules;
    }

    public function prepare(SchemaField $field, $value): mixed
    {
        if (($value === null || $value === '') && $field->getAttribute('default_value')) {
            return $field->getAttribute('default_value');
        }

        return $value;
    }

    /**
     * Get options for this field
     */
    protected function getOptions(SchemaField $field): array
    {
        // Directly defined options
        if ($options = $field->getAttribute('options')) {
            return is_array($options) ? $options : [];
        }

        // Source-based options
        $source = $field->getAttribute('source');
        $sourceOptions = $field->getAttribute('source_options', []);

        if ($source && method_exists($this, "getOptionsFrom{$source}")) {
            return $this->{"getOptionsFrom{$source}"}($sourceOptions);
        }

        return [];
    }

    /**
     * Get options from a custom source
     */
    protected function getOptionsFromCustom(array $options): array
    {
        // This would be implemented to fetch options from a custom source
        return [];
    }
}
