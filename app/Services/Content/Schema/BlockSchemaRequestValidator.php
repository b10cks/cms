<?php

namespace App\Services\Content\Schema;

use App\Models\Space\DataSource;
use App\Models\Space\FieldPlugin;
use App\Services\Content\Serial\ScopeKeyBuilder;
use App\Services\Content\Serial\SerialFieldConfig;
use App\Services\Content\Serial\TemplateRenderer;

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
        'options',
        'link',
        'asset',
        'multi_assets',
        'icon',
        'geo',
        'price',
        'references',
        'date',
        'meta',
        'blocks',
        'table',
        'plugin',
        'serial',
    ];

    protected const array GEO_KEY_STYLES = ['latitude_longitude', 'lat_lng', 'lat_lon'];

    public function __construct(
        protected SchemaNormalizer $schemaNormalizer,
        protected OptionChoiceResolver $optionChoiceResolver,
        protected TemplateRenderer $templateRenderer,
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

            if (\in_array($type, ['option', 'options'], true)) {
                $errors = $this->validateOptionField($errors, $key, $field, $type);
            }

            if ($type === 'table') {
                $errors = $this->validateTableField($errors, $key, $field);
            }

            if ($type === 'geo') {
                $errors = $this->validateGeoField($errors, $key, $field);
            }

            if ($type === 'price') {
                $errors = $this->validatePriceField($errors, $key, $field);
            }

            if ($type === 'plugin') {
                $errors = $this->validatePluginField($errors, $key, $field);
            }

            if ($type === 'serial') {
                $errors = $this->validateSerialField($errors, $key, $field, $normalizedSchema);
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
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $field
     * @return array<string, array<int, string>>
     */
    protected function validateOptionField(array $errors, string $key, array $field, string $type): array
    {
        $source = $field['source'] ?? 'self';

        if (! in_array($source, ['self', 'datasource'], true)) {
            $errors["schema.{$key}.source"][] = 'The source must be either self or datasource.';

            return $errors;
        }

        if ($source === 'datasource') {
            $dataSourceId = $field['data_source_id'] ?? null;

            if (! is_string($dataSourceId) || $dataSourceId === '') {
                $errors["schema.{$key}.data_source_id"][] = 'A datasource is required when the source is datasource.';
            } elseif (! DataSource::query()->whereKey($dataSourceId)->exists()) {
                $errors["schema.{$key}.data_source_id"][] = 'The selected datasource does not exist.';
            }
        }

        $allowedValues = $this->optionChoiceResolver->resolveAllowedValues($field);

        if ($type === 'option') {
            if (
                \array_key_exists('default', $field) &&
                $field['default'] !== null &&
                ! in_array($field['default'], $allowedValues, true)
            ) {
                $errors["schema.{$key}.default"][] = 'The default value must be one of the allowed options.';
            }

            return $errors;
        }

        if (! is_array($field['default'] ?? null)) {
            $errors["schema.{$key}.default"][] = 'The default value must be an array of allowed options.';
        } else {
            foreach ($field['default'] as $index => $value) {
                if (! is_string($value) || ! in_array($value, $allowedValues, true)) {
                    $errors["schema.{$key}.default.{$index}"][] = 'The default value must be one of the allowed options.';
                }
            }
        }

        $min = $field['min'] ?? ($field['validation']['min'] ?? null);
        $max = $field['max'] ?? ($field['validation']['max'] ?? null);

        if ($min !== null && filter_var($min, FILTER_VALIDATE_INT) === false) {
            $errors["schema.{$key}.min"][] = 'The minimum value must be an integer or null.';
        }

        if ($max !== null && filter_var($max, FILTER_VALIDATE_INT) === false) {
            $errors["schema.{$key}.max"][] = 'The maximum value must be an integer or null.';
        }

        if (
            $min !== null &&
            $max !== null &&
            filter_var($min, FILTER_VALIDATE_INT) !== false &&
            filter_var($max, FILTER_VALIDATE_INT) !== false &&
            (int) $min > (int) $max
        ) {
            $errors["schema.{$key}.min"][] = 'The minimum value may not be greater than the maximum value.';
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

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $field
     * @return array<string, array<int, string>>
     */
    protected function validateTableField(array $errors, string $key, array $field): array
    {
        $columns = $field['columns'] ?? null;

        if (! \is_array($columns) || $columns === []) {
            $errors["schema.{$key}.columns"][] = 'The columns field must contain at least one column.';

            return $errors;
        }

        $seenKeys = [];

        foreach ($columns as $index => $column) {
            if (! \is_array($column)) {
                $errors["schema.{$key}.columns.{$index}"][] = 'Each column must be a valid configuration object.';
                continue;
            }

            $columnKey = trim((string) ($column['key'] ?? ''));

            if ($columnKey === '') {
                $errors["schema.{$key}.columns.{$index}.key"][] = 'The column key is required.';
            } elseif (! preg_match('/^[a-z0-9_-]+$/', $columnKey)) {
                $errors["schema.{$key}.columns.{$index}.key"][] = 'The column key may only contain lowercase letters, numbers, dashes, and underscores.';
            } elseif (isset($seenKeys[$columnKey])) {
                $errors["schema.{$key}.columns.{$index}.key"][] = 'The column key must be unique.';
            } else {
                $seenKeys[$columnKey] = true;
            }

            if (trim((string) ($column['label'] ?? '')) === '') {
                $errors["schema.{$key}.columns.{$index}.label"][] = 'The column label is required.';
            }

            $columnType = (string) ($column['type'] ?? '');

            if (! \in_array($columnType, ['text', 'number', 'option', 'boolean'], true)) {
                $errors["schema.{$key}.columns.{$index}.type"][] = 'The column type is not supported.';
            }

            if ($columnType === 'option') {
                $errors = $this->validateTableOptionColumn($errors, $key, $index, $column);
            }
        }

        if (! \is_bool($field['has_thead'] ?? null)) {
            $errors["schema.{$key}.has_thead"][] = 'The table header toggle must be true or false.';
        }

        $min = $field['min'] ?? ($field['validation']['min'] ?? null);
        $max = $field['max'] ?? ($field['validation']['max'] ?? null);

        if ($min !== null && filter_var($min, FILTER_VALIDATE_INT) === false) {
            $errors["schema.{$key}.min"][] = 'The minimum row count must be an integer or null.';
        }

        if ($max !== null && filter_var($max, FILTER_VALIDATE_INT) === false) {
            $errors["schema.{$key}.max"][] = 'The maximum row count must be an integer or null.';
        }

        if (
            $min !== null &&
            $max !== null &&
            filter_var($min, FILTER_VALIDATE_INT) !== false &&
            filter_var($max, FILTER_VALIDATE_INT) !== false &&
            (int) $min > (int) $max
        ) {
            $errors["schema.{$key}.min"][] = 'The minimum row count may not be greater than the maximum row count.';
        }

        return $errors;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $field
     * @return array<string, array<int, string>>
     */
    protected function validateGeoField(array $errors, string $key, array $field): array
    {
        $keyStyle = $field['key_style'] ?? 'lat_lng';

        if (! in_array($keyStyle, self::GEO_KEY_STYLES, true)) {
            $errors["schema.{$key}.key_style"][] = 'The key style is not supported.';
        }

        if (array_key_exists('altitude', $field) && ! is_bool($field['altitude'])) {
            $errors["schema.{$key}.altitude"][] = 'The altitude toggle must be true or false.';
        }

        if (array_key_exists('map', $field) && ! is_bool($field['map'])) {
            $errors["schema.{$key}.map"][] = 'The map toggle must be true or false.';
        }

        return $errors;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $field
     * @return array<string, array<int, string>>
     */
    protected function validatePriceField(array $errors, string $key, array $field): array
    {
        $baseCurrency = $field['base_currency'] ?? '';

        if (! is_string($baseCurrency) || ! preg_match('/^[A-Z]{1,3}(-[A-Z]{1,3})?$/', $baseCurrency)) {
            $errors["schema.{$key}.base_currency"][] = 'The base currency must be a 1–3 letter ISO 4217 code (uppercase), optionally prefixed with a region (e.g. US-USD).';
        }

        if (array_key_exists('currencies', $field)) {
            if (! is_array($field['currencies'])) {
                $errors["schema.{$key}.currencies"][] = 'The currencies must be an array.';
            } else {
                foreach ($field['currencies'] as $index => $code) {
                    if (! is_string($code) || ! preg_match('/^[A-Z]{1,3}(-[A-Z]{1,3})?$/', $code)) {
                        $errors["schema.{$key}.currencies.{$index}"][] = 'Each currency must be a 1–3 letter ISO 4217 code (uppercase), optionally prefixed with a region (e.g. US-USD).';
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $field
     * @return array<string, array<int, string>>
     */
    protected function validatePluginField(array $errors, string $key, array $field): array
    {
        $handle = $field['plugin_handle'] ?? null;

        if (! is_string($handle) || $handle === '') {
            $errors["schema.{$key}.plugin_handle"][] = 'A field plugin is required.';
        } elseif (! FieldPlugin::query()->where('handle', $handle)->exists()) {
            $errors["schema.{$key}.plugin_handle"][] = 'The selected field plugin does not exist.';
        }

        if (array_key_exists('options', $field)) {
            if (! is_array($field['options'])) {
                $errors["schema.{$key}.options"][] = 'The options must be an object of string values.';
            } else {
                foreach ($field['options'] as $optionKey => $value) {
                    if (! is_string($value)) {
                        $errors["schema.{$key}.options.{$optionKey}"][] = 'Each option value must be a string.';
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $field
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<string, array<int, string>>
     */
    protected function validateSerialField(array $errors, string $key, array $field, array $schema): array
    {
        $format = (string) ($field['format'] ?? '');

        foreach ($this->templateRenderer->validate($format) as $message) {
            $errors["schema.{$key}.format"][] = $message;
        }

        // A format that never draws a number would give every entry in the
        // scope the same identifier — the uniqueness index would reject the
        // second one at creation time rather than here, which is far too late.
        if (! isset($errors["schema.{$key}.format"]) && ! $this->templateRenderer->requiresNumber($format)) {
            $errors["schema.{$key}.format"][] = 'The format must contain a {counter} token.';
        }

        // `{field:x}` can only read a field that is filled before the serial is
        // rendered, i.e. one that exists on the same block and is not itself a
        // generated value.
        preg_match_all(TemplateRenderer::TOKEN_PATTERN, $format, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (($match[1] ?? '') !== 'field') {
                continue;
            }

            $referenced = $match[2] ?? '';
            // The resolver reads with data_get, so `{field:specs.width}` is a
            // dot path into the field named by the first segment.
            $target = $schema[explode('.', $referenced, 2)[0]] ?? null;

            if ($referenced === '' || $target === null) {
                $errors["schema.{$key}.format"][] = sprintf('`{field:%s}` references an unknown field.', $referenced);

                continue;
            }

            if (($target['type'] ?? '') === 'serial') {
                $errors["schema.{$key}.format"][] = sprintf(
                    '`{field:%s}` references another generated field, which is not available yet.',
                    $referenced,
                );
            }
        }

        foreach ($field['scope'] ?? [] as $dimension) {
            if (! in_array($dimension, ScopeKeyBuilder::DIMENSIONS, true)) {
                $errors["schema.{$key}.scope"][] = sprintf('`%s` is not a supported scope.', $dimension);
            }
        }

        if (! in_array($field['unique'] ?? 'scope', ScopeKeyBuilder::UNIQUE_MODES, true)) {
            $errors["schema.{$key}.unique"][] = 'The uniqueness mode is not supported.';
        }

        if (! in_array($field['on_move'] ?? 'keep', SerialFieldConfig::ON_MOVE_MODES, true)) {
            $errors["schema.{$key}.on_move"][] = 'The move behaviour is not supported.';
        }

        // `translatable` needs no check here: the normalizer already forces it
        // to false for every type outside TRANSLATABLE_TYPES, so a serial can
        // never arrive marked translatable.

        return $errors;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $column
     * @return array<string, array<int, string>>
     */
    protected function validateTableOptionColumn(array $errors, string $key, int $index, array $column): array
    {
        $source = $column['source'] ?? 'self';

        if (! \in_array($source, ['self', 'datasource'], true)) {
            $errors["schema.{$key}.columns.{$index}.source"][] = 'The source must be either self or datasource.';

            return $errors;
        }

        if ($source === 'datasource') {
            $dataSourceId = $column['data_source_id'] ?? null;

            if (! \is_string($dataSourceId) || $dataSourceId === '') {
                $errors["schema.{$key}.columns.{$index}.data_source_id"][] = 'A datasource is required when the source is datasource.';
            } elseif (! DataSource::query()->whereKey($dataSourceId)->exists()) {
                $errors["schema.{$key}.columns.{$index}.data_source_id"][] = 'The selected datasource does not exist.';
            }
        }

        return $errors;
    }
}
