<?php

namespace App\Services\Content\Schema;

class BlockSchemaRequestValidator
{
    protected const array SUPPORTED_TYPES = [
        'text',
        'textarea',
        'markdown',
        'richtext',
        'number',
        'boolean',
        'option',
        'link',
        'asset',
        'multi_assets',
        'references',
        'date',
        'meta',
        'blocks',
    ];

    public function __construct(
        protected SchemaNormalizer $schemaNormalizer,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public function validate(array $schema, ?array $editor = null): array
    {
        $normalizedSchema = $this->schemaNormalizer->normalizeSchema($schema);
        $normalizedEditor = $this->schemaNormalizer->normalizeEditor($editor, array_keys($normalizedSchema));
        $errors = [];

        foreach ($normalizedSchema as $key => $field) {
            $type = $field['type'];

            if (! in_array($type, self::SUPPORTED_TYPES, true)) {
                $errors["schema.{$key}.type"][] = 'This field type is not supported.';
            }

            if (($field['translatable'] ?? false) && ! $this->schemaNormalizer->supportsTranslation($type)) {
                $errors["schema.{$key}.translatable"][] = 'This field type cannot be marked as translatable.';
            }

            if (($field['indexable'] ?? false) && ! $this->schemaNormalizer->supportsIndexing($type)) {
                $errors["schema.{$key}.indexable"][] = 'This field type cannot be indexed.';
            }

            foreach (($field['conditions']['rules'] ?? []) as $index => $rule) {
                $controllerKey = (string) ($rule['field'] ?? '');
                $controller = $normalizedSchema[$controllerKey] ?? null;

                if (! $controller) {
                    $errors["schema.{$key}.conditions.rules.{$index}.field"][] = 'This condition references an unknown field.';
                    continue;
                }

                if (! in_array($rule['operator'] ?? '', ConditionEvaluator::OPERATORS, true)) {
                    $errors["schema.{$key}.conditions.rules.{$index}.operator"][] = 'This condition operator is not supported.';
                }

                if (! in_array($rule['operator'] ?? '', $this->allowedConditionOperators($controller['type']), true)) {
                    $errors["schema.{$key}.conditions.rules.{$index}.operator"][] = 'This operator is not valid for the controlling field type.';
                }
            }

            $validationKeys = array_keys($field['validation'] ?? []);
            $allowedValidationKeys = SchemaNormalizer::VALIDATION_KEYS_BY_TYPE[$type] ?? [];

            foreach ($validationKeys as $validationKey) {
                if (! in_array($validationKey, $allowedValidationKeys, true)) {
                    $errors["schema.{$key}.validation.{$validationKey}"][] = 'This validation option is not supported for the field type.';
                }
            }
        }

        $editorItems = [];

        foreach ($normalizedEditor as $pageIndex => $page) {
            foreach ($page['items'] as $itemIndex => $item) {
                $editorItems[] = $item;

                if (! array_key_exists($item, $normalizedSchema)) {
                    $errors["editor.{$pageIndex}.items.{$itemIndex}"][] = 'This editor item references an unknown schema field.';
                }
            }
        }

        foreach (array_keys($normalizedSchema) as $schemaKey) {
            if (! in_array($schemaKey, $editorItems, true)) {
                $errors["editor"][] = "The schema field '{$schemaKey}' is not assigned to an editor page.";
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    protected function allowedConditionOperators(string $type): array
    {
        return match ($type) {
            'boolean' => ['equals', 'not_equals', 'is_empty', 'is_not_empty'],
            'number', 'date' => ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'is_empty', 'is_not_empty'],
            default => ['equals', 'not_equals', 'in', 'not_in', 'contains', 'is_empty', 'is_not_empty'],
        };
    }
}
