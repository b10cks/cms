<?php

namespace App\Services\Content\Schema;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Services\Content\ContentI18nResolver;
use Illuminate\Support\Arr;

class ContentSchemaValidator
{
    public function __construct(
        protected readonly ContentI18nResolver $contentI18nResolver,
        protected readonly ContentSchemaBuilder $builder,
        protected readonly FieldVisibilityPruner $pruner,
        protected readonly OptionChoiceResolver $optionChoiceResolver,
        protected readonly ContentSchemaValueMerger $contentSchemaValueMerger,
    ) {
    }

    public function validateSubmission(
        Space $space,
        Block $block,
        array $submittedContent,
        ?Content $content = null,
        ?string $languageIso = null,
        ?string $i18nParentId = null,
        string $mode = 'save',
    ): ContentSchemaValidationResult {
        $effectiveBase = $this->resolveEffectiveBase($space, $content, $languageIso, $i18nParentId);
        $effectiveContent = $this->contentSchemaValueMerger->mergeForSchema(
            $block->schema?->toArray() ?? [],
            $effectiveBase['content'],
            $submittedContent,
            $effectiveBase['mode'] === 'overlay',
        );
        $tree = $this->builder->build(
            $block,
            $submittedContent,
            $effectiveContent,
        );
        $sanitizedContent = $this->pruner->prune($tree);
        $sanitizedTree = $this->builder->build(
            $block,
            $sanitizedContent,
            $this->contentSchemaValueMerger->mergeForSchema(
                $block->schema?->toArray() ?? [],
                $effectiveBase['content'],
                $sanitizedContent,
                $effectiveBase['mode'] === 'overlay',
            ),
        );

        return new ContentSchemaValidationResult(
            content: $sanitizedContent,
            tree: $sanitizedTree,
            errors: $this->collectErrors($sanitizedTree, $mode),
            warnings: $this->collectWarnings($sanitizedTree, $mode),
        );
    }

    public function validateVersion(
        Space $space,
        Content $content,
        ContentVersion $version,
        string $mode = 'publish',
    ): ContentSchemaValidationResult {
        $content->loadMissing('block');

        return $this->validateSubmission(
            $space,
            $content->block,
            $version->content ?? [],
            $content,
            $content->language_iso,
            $content->i18n_parent_id,
            $mode,
        );
    }

    protected function resolveEffectiveBase(
        Space $space,
        ?Content $content,
        ?string $languageIso,
        ?string $i18nParentId,
    ): array {
        $requestedLanguage = strtolower((string) ($languageIso ?? $content?->language_iso ?? $space->settings->getDefaultLanguage()));
        $resolverContent = $content;

        if (!$resolverContent && $i18nParentId) {
            $resolverContent = Content::query()
                ->where('id', $i18nParentId)
                ->whereNull('deleted_at')
                ->first();
        }

        if (!$resolverContent) {
            return [
                'content' => [],
                'mode' => 'independent',
            ];
        }

        $resolved = $this->contentI18nResolver->resolve(
            $space,
            $resolverContent,
            $requestedLanguage,
            'current',
        );

        return [
            'content' => $resolved->effectiveMode === 'overlay'
                ? ($resolved->effectiveContent ?? [])
                : [],
            'mode' => $resolved->effectiveMode,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function collectErrors(ContentSchemaTree $tree, string $mode = 'publish'): array
    {
        $errors = [];

        foreach ($tree->flatten() as $node) {
            if (!$node->visible) {
                continue;
            }

            $messages = $this->validateNode($node, $mode);

            if ($messages !== []) {
                $errors[$node->dotPath()] = $messages;
            }
        }

        return $errors;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function collectWarnings(ContentSchemaTree $tree, string $mode = 'save'): array
    {
        $warnings = [];

        foreach ($tree->flatten() as $node) {
            if (!$node->visible) {
                continue;
            }

            $messages = $this->warnNode($node, $mode);

            if ($messages !== []) {
                $warnings[$node->dotPath()] = $messages;
            }
        }

        return $warnings;
    }

    /**
     * @return array<int, string>
     */
    protected function validateNode(ContentSchemaNode $node, string $mode = 'publish'): array
    {
        $field = $node->field;
        $type = $field->getType();
        $value = $node->effectiveValue;

        if ($field->isRequired() && $this->isEmpty($value)) {
            return $mode === 'publish'
                ? [sprintf('%s is required.', $field->getLabel())]
                : [];
        }

        if ($this->isEmpty($value) && ! $this->shouldValidateEmptyValue($field, $value)) {
            return [];
        }

        return match ($type) {
            'text', 'textarea', 'markdown', 'richtext' => $this->validateTextLike($field, $value),
            'number' => $this->validateNumber($field, $value),
            'boolean' => is_bool($value) ? [] : [sprintf('%s must be true or false.', $field->getLabel())],
            'option' => $this->validateOption($field, $value),
            'options' => $this->validateOptions($field, $value),
            'link' => $this->validateLink($field, $value),
            'date' => $this->validateDate($field, $value),
            'asset' => $this->validateAsset($field, $value),
            'multi_assets' => $this->validateMultiAssets($field, $value),
            'references' => $this->validateReferences($field, $value),
            'blocks' => $this->validateBlocks($field, $value),
            'meta' => $this->validateMeta($field, $value),
            'table' => $this->validateTable($field, $value),
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    protected function warnNode(ContentSchemaNode $node, string $mode = 'save'): array
    {
        $field = $node->field;
        $value = $node->effectiveValue;

        if ($mode === 'save' && $field->isRequired() && $this->isEmpty($value)) {
            return [sprintf('%s is required.', $field->getLabel())];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function validateTextLike(SchemaField $field, mixed $value): array
    {
        $text = is_string($value) ? $value : json_encode($value);
        $length = mb_strlen((string) $text);
        $validation = $field->getValidation();
        $messages = [];

        if (($minLength = Arr::get($validation, 'min_length')) !== null && $length < (int) $minLength) {
            $messages[] = sprintf('%s must be at least %d characters.', $field->getLabel(), $minLength);
        }

        if (($maxLength = Arr::get($validation, 'max_length')) !== null && $length > (int) $maxLength) {
            $messages[] = sprintf('%s may not be greater than %d characters.', $field->getLabel(), $maxLength);
        }

        if (($pattern = Arr::get($validation, 'pattern')) && @preg_match($pattern, '') !== false && !preg_match($pattern, (string) $text)) {
            $messages[] = sprintf('%s has an invalid format.', $field->getLabel());
        }

        return $messages;
    }

    /**
     * @return array<int, string>
     */
    protected function validateNumber(SchemaField $field, mixed $value): array
    {
        if (!is_numeric($value)) {
            return [sprintf('%s must be a number.', $field->getLabel())];
        }

        $number = (float) $value;
        $validation = $field->getValidation();
        $messages = [];

        if (($min = Arr::get($validation, 'min')) !== null && $number < (float) $min) {
            $messages[] = sprintf('%s must be at least %s.', $field->getLabel(), $min);
        }

        if (($max = Arr::get($validation, 'max')) !== null && $number > (float) $max) {
            $messages[] = sprintf('%s may not be greater than %s.', $field->getLabel(), $max);
        }

        return $messages;
    }

    /**
     * @return array<int, string>
     */
    protected function validateOption(SchemaField $field, mixed $value): array
    {
        $allowed = $this->optionChoiceResolver->resolveAllowedValues($field);
        $source = $field->getAttribute('source', 'self');

        if ((! empty($allowed) || $source === 'datasource') && !in_array($value, $allowed, true)) {
            return [sprintf('%s must be one of the allowed options.', $field->getLabel())];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function validateOptions(SchemaField $field, mixed $value): array
    {
        if (!is_array($value)) {
            return [sprintf('%s must be an option collection.', $field->getLabel())];
        }

        $messages = $this->validateCountBounds(
            $field,
            count($value),
            $field->getAttribute('min') ?? $field->getValidationValue('min_items') ?? $field->getValidationValue('min'),
            $field->getAttribute('max') ?? $field->getValidationValue('max_items') ?? $field->getValidationValue('max'),
            'options',
        );

        $allowed = $this->optionChoiceResolver->resolveAllowedValues($field);
        $source = $field->getAttribute('source', 'self');

        foreach ($value as $entry) {
            if (!is_string($entry) || ((! empty($allowed) || $source === 'datasource') && !in_array($entry, $allowed, true))) {
                $messages[] = sprintf('%s contains an invalid option.', $field->getLabel());
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * @return array<int, string>
     */
    protected function validateLink(SchemaField $field, mixed $value): array
    {
        if (!is_array($value) || !isset($value['type'])) {
            return [sprintf('%s must be a valid link.', $field->getLabel())];
        }

        return match ($value['type']) {
            'url' => filter_var($value['url'] ?? null, FILTER_VALIDATE_URL)
            ? []
            : [sprintf('%s must contain a valid URL.', $field->getLabel())],
            'email' => ($field->getAttribute('email_link_type', false) && filter_var($value['email'] ?? null, FILTER_VALIDATE_EMAIL))
            ? []
            : [sprintf('%s must contain a valid email link.', $field->getLabel())],
            'internal' => !empty($value['content'])
            ? []
            : [sprintf('%s must reference content.', $field->getLabel())],
            default => [sprintf('%s contains an unsupported link type.', $field->getLabel())],
        };
    }

    /**
     * @return array<int, string>
     */
    protected function validateDate(SchemaField $field, mixed $value): array
    {
        if (!is_string($value) || strtotime($value) === false) {
            return [sprintf('%s must be a valid date.', $field->getLabel())];
        }

        $timestamp = strtotime($value);
        $validation = $field->getValidation();
        $messages = [];

        if (($min = $field->getAttribute('min') ?? Arr::get($validation, 'min')) && strtotime((string) $min) > $timestamp) {
            $messages[] = sprintf('%s must be on or after %s.', $field->getLabel(), $min);
        }

        if (($max = $field->getAttribute('max') ?? Arr::get($validation, 'max')) && strtotime((string) $max) < $timestamp) {
            $messages[] = sprintf('%s must be on or before %s.', $field->getLabel(), $max);
        }

        return $messages;
    }

    /**
     * @return array<int, string>
     */
    protected function validateAsset(SchemaField $field, mixed $value): array
    {
        if (!is_array($value) || ($value['type'] ?? null) !== 'asset' || empty($value['id'])) {
            return [sprintf('%s must reference an asset.', $field->getLabel())];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function validateMultiAssets(SchemaField $field, mixed $value): array
    {
        if (!is_array($value)) {
            return [sprintf('%s must be an asset collection.', $field->getLabel())];
        }

        $messages = $this->validateCountBounds(
            $field,
            count($value),
            $field->getAttribute('min') ?? $field->getValidationValue('min_items'),
            $field->getAttribute('max') ?? $field->getValidationValue('max_items'),
            'assets',
        );

        foreach ($value as $asset) {
            $messages = [...$messages, ...$this->validateAsset($field, $asset)];
        }

        return array_values(array_unique($messages));
    }

    /**
     * @return array<int, string>
     */
    protected function validateReferences(SchemaField $field, mixed $value): array
    {
        if (!is_array($value)) {
            return [sprintf('%s must be a reference collection.', $field->getLabel())];
        }

        $messages = $this->validateCountBounds(
            $field,
            count($value),
            $field->getAttribute('min') ?? $field->getValidationValue('min_items'),
            $field->getAttribute('max') ?? $field->getValidationValue('max_items'),
            'references',
        );

        foreach ($value as $reference) {
            if (!is_string($reference) || $reference === '') {
                $messages[] = sprintf('%s contains an invalid reference.', $field->getLabel());
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * @return array<int, string>
     */
    protected function validateBlocks(SchemaField $field, mixed $value): array
    {
        if (!is_array($value)) {
            return [sprintf('%s must be a block collection.', $field->getLabel())];
        }

        return $this->validateCountBounds(
            $field,
            count($value),
            $field->getAttribute('min') ?? $field->getValidationValue('min_items') ?? $field->getValidationValue('min'),
            $field->getAttribute('max') ?? $field->getValidationValue('max_items') ?? $field->getValidationValue('max'),
            'blocks',
        );
    }

    /**
     * @return array<int, string>
     */
    protected function validateMeta(SchemaField $field, mixed $value): array
    {
        if (!is_array($value)) {
            return [sprintf('%s must be a metadata object.', $field->getLabel())];
        }

        $messages = [];

        foreach (['title', 'description', 'canonical', 'robots', 'ogTitle', 'ogDescription'] as $key) {
            if (isset($value[$key]) && !is_string($value[$key])) {
                $messages[] = sprintf('%s.%s must be a string.', $field->getLabel(), $key);
            }
        }

        if (isset($value['ogImage']) && $value['ogImage'] !== null) {
            $messages = [...$messages, ...$this->validateAsset($field, $value['ogImage'])];
        }

        return array_values(array_unique($messages));
    }

    /**
     * @return array<int, string>
     */
    protected function validateTable(SchemaField $field, mixed $value): array
    {
        if (! is_array($value)) {
            return [sprintf('%s must be a table object.', $field->getLabel())];
        }

        if (! is_array($value['header'] ?? null)) {
            return [sprintf('%s must contain a valid header object.', $field->getLabel())];
        }

        if (! is_array($value['rows'] ?? null)) {
            return [sprintf('%s must contain a valid rows array.', $field->getLabel())];
        }

        $messages = [];
        $columns = collect($field->getAttribute('columns', []))
            ->filter(fn (mixed $column): bool => is_array($column) && isset($column['key']))
            ->mapWithKeys(fn (array $column): array => [(string) $column['key'] => $column])
            ->all();

        foreach ($value['header'] as $headerKey => $headerValue) {
            if (! is_string($headerKey) || ! array_key_exists($headerKey, $columns)) {
                $messages[] = sprintf('%s contains an unknown header column.', $field->getLabel());
                continue;
            }

            if (! is_string($headerValue)) {
                $messages[] = sprintf('%s header values must be strings.', $field->getLabel());
            }
        }

        $messages = [
            ...$messages,
            ...$this->validateCountBounds(
                $field,
                count($value['rows']),
                $field->getAttribute('min') ?? $field->getValidationValue('min'),
                $field->getAttribute('max') ?? $field->getValidationValue('max'),
                'rows',
            ),
        ];

        $seenRowIds = [];

        foreach ($value['rows'] as $row) {
            if (! is_array($row)) {
                $messages[] = sprintf('%s contains an invalid row.', $field->getLabel());
                continue;
            }

            $rowId = $row['id'] ?? null;

            if (! is_string($rowId) || $rowId === '') {
                $messages[] = sprintf('%s rows must have a unique string id.', $field->getLabel());
                continue;
            }

            if (isset($seenRowIds[$rowId])) {
                $messages[] = sprintf('%s rows must have unique ids.', $field->getLabel());
            }

            $seenRowIds[$rowId] = true;
            $cells = $row['cells'] ?? null;

            if (! is_array($cells)) {
                $messages[] = sprintf('%s row cells must be an object.', $field->getLabel());
                continue;
            }

            foreach ($cells as $cellKey => $cellValue) {
                $column = $columns[$cellKey] ?? null;

                if (! $column) {
                    $messages[] = sprintf('%s contains a cell for an unknown column.', $field->getLabel());
                    continue;
                }

                $messages = [
                    ...$messages,
                    ...$this->validateTableCell($field, $column, $cellValue),
                ];
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * @param  array<string, mixed>  $column
     * @return array<int, string>
     */
    protected function validateTableCell(SchemaField $field, array $column, mixed $value): array
    {
        return match ($column['type'] ?? 'text') {
            'text' => is_string($value)
                ? []
                : [sprintf('%s text cells must be strings.', $field->getLabel())],
            'number' => $value === null || is_int($value) || is_float($value)
                ? []
                : [sprintf('%s number cells must be a number or null.', $field->getLabel())],
            'boolean' => is_bool($value)
                ? []
                : [sprintf('%s boolean cells must be true or false.', $field->getLabel())],
            'option' => $this->validateTableOptionCell($field, $column, $value),
            default => [sprintf('%s contains an unsupported table cell type.', $field->getLabel())],
        };
    }

    /**
     * @param  array<string, mixed>  $column
     * @return array<int, string>
     */
    protected function validateTableOptionCell(SchemaField $field, array $column, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $allowed = $this->optionChoiceResolver->resolveAllowedValues($column);
        $source = $column['source'] ?? 'self';

        if (! is_string($value) || ((! empty($allowed) || $source === 'datasource') && ! in_array($value, $allowed, true))) {
            return [sprintf('%s option cells must use an allowed option.', $field->getLabel())];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function validateCountBounds(
        SchemaField $field,
        int $count,
        mixed $min,
        mixed $max,
        string $label,
    ): array {
        $messages = [];

        if ($min !== null && $count < (int) $min) {
            $messages[] = sprintf('%s must contain at least %d %s.', $field->getLabel(), $min, $label);
        }

        if ($max !== null && $count > (int) $max) {
            $messages[] = sprintf('%s may not contain more than %d %s.', $field->getLabel(), $max, $label);
        }

        return $messages;
    }

    protected function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    protected function shouldValidateEmptyValue(SchemaField $field, mixed $value): bool
    {
        return is_array($value)
            && in_array($field->getType(), ['options', 'multi_assets', 'references', 'blocks'], true);
    }
}
