<?php

namespace App\Services\Content\Schema\Types;

use App\Services\Content\Schema\SchemaField;

class LinkTypeHandler extends AbstractTypeHandler
{
    public function getType(): string
    {
        return 'link';
    }

    protected function getValidationRules(SchemaField $field): array
    {
        $rules = parent::getValidationRules($field);

        // Add validation for URL
        $rules[] = function ($attribute, $value, $fail) use ($field) {
            if (empty($value)) {
                return;
            }

            if (!isset($value['type']) || !isset($value['value'])) {
                $fail('The link must have a type and value.');
                return;
            }

            // Validate based on link type
            switch ($value['type']) {
                case 'url':
                    if (!filter_var($value['value'], FILTER_VALIDATE_URL)) {
                        $fail('The URL format is invalid.');
                    }
                    break;
                case 'email':
                    if (!$field->getAttribute('email_link_type', true)) {
                        $fail('Email links are not allowed for this field.');
                        break;
                    }

                    if (!filter_var($value['value'], FILTER_VALIDATE_EMAIL)) {
                        $fail('The email format is invalid.');
                    }
                    break;
                case 'asset':
                    if (!$field->getAttribute('asset_link_type', true)) {
                        $fail('Asset links are not allowed for this field.');
                    }
                    break;
            }
        };

        return $rules;
    }

    public function getFrontendRules(SchemaField $field): array
    {
        $rules = parent::getFrontendRules($field);

        $rules['type'] = 'link';
        $rules['allowEmail'] = $field->getAttribute('email_link_type', true);
        $rules['allowAsset'] = $field->getAttribute('asset_link_type', true);
        $rules['allowTargetBlank'] = $field->getAttribute('allow_target_blank', false);
        $rules['showAnchor'] = $field->getAttribute('show_anchor', false);

        return $rules;
    }
}
