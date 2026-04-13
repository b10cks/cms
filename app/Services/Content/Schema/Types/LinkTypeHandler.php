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

            if (! is_array($value) || ! isset($value['type'])) {
                $fail('The link must have a type.');
                return;
            }

            // Validate based on link type
            switch ($value['type']) {
                case 'url':
                    $linkValue = $value['url'] ?? null;

                    if (! is_string($linkValue) || trim($linkValue) === '') {
                        $fail('The URL format is invalid.');
                        break;
                    }

                    if (! $this->isValidUrlLikeLink($linkValue)) {
                        $fail('The URL format is invalid.');
                    }
                    break;
                case 'email':
                    if (! $field->getAttribute('email_link_type', true)) {
                        $fail('Email links are not allowed for this field.');
                        break;
                    }

                    if (! filter_var($value['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
                        $fail('The email format is invalid.');
                        break;
                    }

                    foreach (['subject', 'body'] as $optionalAttribute) {
                        if (
                            array_key_exists($optionalAttribute, $value)
                            && $value[$optionalAttribute] !== null
                            && ! is_string($value[$optionalAttribute])
                        ) {
                            $fail("The {$optionalAttribute} must be a string.");
                        }
                    }

                    foreach (['cc', 'bcc'] as $recipientAttribute) {
                        if (! $this->hasValidRecipientList($value[$recipientAttribute] ?? null)) {
                            $fail("The {$recipientAttribute} list is invalid.");
                        }
                    }
                    break;
                case 'internal':
                    if (! is_string($value['content'] ?? null) || trim($value['content']) === '') {
                        $fail('The internal link must reference content.');
                    }
                    break;
                case 'asset':
                    if (! $field->getAttribute('asset_link_type', true)) {
                        $fail('Asset links are not allowed for this field.');
                    }
                    break;
            }
        };

        return $rules;
    }

    protected function isValidUrlLikeLink(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return true;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        return preg_match('/^[a-z][a-z0-9+.-]*:[^\s]+$/i', $value) === 1;
    }

    protected function hasValidRecipientList(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return true;
        }

        $entries = preg_split('/[;,]+/', $normalized) ?: [];

        foreach ($entries as $entry) {
            $email = trim($entry);

            if ($email === '') {
                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
        }

        return true;
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
