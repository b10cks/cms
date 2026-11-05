<?php

namespace App\Services\Ai\Assets;

use App\Models\Management\Space;
use App\Models\Space\AssetFolder;
use App\Services\Asset\AssetMetadataFieldResolver;

/**
 * What AI may write into asset metadata: the fields the folder enables and
 * allows for AI (`ai.asset_classification.allowed_fields`, folder overrides),
 * under the language keys asset data is stored with (`_default`, `de`, ...),
 * within the editorial length limits of each field.
 */
class AssetFieldRules
{
    private const MAX_LENGTHS = ['alt' => 125, 'alt_text' => 125, 'title' => 70, 'name' => 70, 'description' => 400, 'caption' => 400];

    private const DEFAULT_MAX_LENGTH = 1000;

    public function __construct(private readonly AssetMetadataFieldResolver $fields) {}

    /**
     * Field keys AI may write for assets in the folder.
     *
     * @return list<string>
     */
    public function keys(Space $space, AssetFolder|string|null $folder): array
    {
        return array_values(array_column($this->fields->getEffectiveClassificationFieldsForFolder($space, $folder), 'key'));
    }

    /**
     * Enabled fields of the folder with their required flag, AI-writable or not.
     *
     * @return list<array{key: string, label: string, required: bool}>
     */
    public function definitions(Space $space, AssetFolder|string|null $folder): array
    {
        return array_values($this->fields->getEffectiveFieldsForFolder($space, $folder));
    }

    /** @return list<string> */
    public function languages(Space $space): array
    {
        return $this->fields->languageKeys($space);
    }

    /** Why the text breaks the field's editorial rules, or null when it fits. */
    public function textProblem(string $field, string $text): ?string
    {
        $limit = self::MAX_LENGTHS[$field] ?? self::DEFAULT_MAX_LENGTH;

        if (mb_strlen($text) > $limit) {
            return "at most {$limit} characters";
        }

        if ($field === 'keywords') {
            $terms = array_filter(array_map('trim', explode(',', $text)));

            if (\count($terms) < 5 || \count($terms) > 10 || \count($terms) !== \count(array_unique($terms))) {
                return '5 to 10 distinct comma-separated terms';
            }
        }

        return null;
    }

    /**
     * Problems of field values for an asset that sits (or will sit) in the
     * folder. Empty strings clear a field and skip the text rules.
     *
     * @param  array<string, mixed>  $fields  language key => field key => value
     * @return list<string>
     */
    public function problems(Space $space, AssetFolder|string|null $folder, array $fields): array
    {
        $languages = $this->languages($space);
        $keys = $this->keys($space, $folder);
        $problems = [];

        foreach ($fields as $language => $values) {
            if (! \in_array($language, $languages, true)) {
                $problems[] = "\"{$language}\" is no asset language. Use one of: ".implode(', ', $languages).' (`_default` is the default language).';

                continue;
            }

            foreach (\is_array($values) ? $values : [] as $field => $value) {
                $path = "{$language}.{$field}";

                if (! \in_array($field, $keys, true)) {
                    $problems[] = "{$path}: the folder has no AI-writable field \"{$field}\". Fields: ".implode(', ', $keys ?: ['none']).'.';
                } elseif ($value !== null && ! \is_string($value)) {
                    $problems[] = "{$path}: must be text.";
                } elseif (\is_string($value) && trim($value) !== '' && $problem = $this->textProblem((string) $field, trim($value))) {
                    $problems[] = "{$path}: {$problem}.";
                }
            }
        }

        return $problems;
    }

    /**
     * Required fields of the folder that stay empty in the given data, as
     * `language.field`. Only the default language is checked, like the editor.
     *
     * @param  array<string, mixed>  $fields  language key => field key => value
     * @return list<string>
     */
    public function missingRequired(Space $space, AssetFolder|string|null $folder, array $fields): array
    {
        $missing = [];
        $default = AssetMetadataFieldResolver::DEFAULT_LANGUAGE_KEY;

        foreach ($this->definitions($space, $folder) as $field) {
            $value = $fields[$default][$field['key']] ?? null;

            if ($field['required'] && (! \is_string($value) || trim($value) === '')) {
                $missing[] = "{$default}.{$field['key']}";
            }
        }

        return $missing;
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || (\is_string($value) && trim($value) === '');
    }
}
