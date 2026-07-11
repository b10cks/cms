<?php

namespace App\Http\Requests\Content;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Models\Space\Content;
use App\Models\Space\ContentSettings;
use App\Services\Content\ContentI18nValidator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class UpsertContentRequest extends FormRequest
{
    use ExternalIdValidation;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization will be handled by policies/middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $contentId = $this->route('content');
        $parentId = $this->input('parent_id');
        $languageIso = $this->input('language_iso');

        $connectionName = new Content()->getConnectionName();

        return [
            'external_id' => $this->externalIdRule(Content::class, $contentId),
            'name' => 'sometimes|required|string|max:100',
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:70',
                Rule::unique($connectionName.'.contents', 'slug')
                    ->when($languageIso, fn ($query) => $query->where('language_iso', $languageIso))
                    ->when($parentId, fn ($query) => $query->where('parent_id', $parentId), fn ($query) => $query->whereNull('parent_id'))
                    ->whereNull('deleted_at')
                    ->ignore($contentId),
            ],
            'settings' => 'nullable|array',
            ...ContentSettings::toValidator('settings'),
            'block_id' => [
                'sometimes',
                'required',
                Rule::exists($connectionName.'.blocks', 'id')
                    ->whereNull('deleted_at'),
            ],
            'parent_id' => [
                'nullable',
                'string',
                Rule::exists($connectionName.'.contents', 'id')
                    ->whereNull('deleted_at'),
            ],
            'position' => 'sometimes|integer|min:0',
            'i18n_parent_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists($connectionName.'.contents', 'id')
                    ->whereNull('deleted_at'),
            ],
            'language_iso' => 'sometimes|required|string|min:2|max:5',
            'content' => 'nullable|array',
            'force' => 'sometimes|boolean',
            'parent_version_id' => 'sometimes|nullable|string',
            'force_conflict' => 'sometimes|boolean',
            ...$this->translationRules(),
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if ($this->boolean('force')) {
                    return;
                }

                $space = $this->route('space');
                if (! $space) {
                    return;
                }

                $content = $this->route('content');
                $childSettingKeys = array_values(array_filter([
                    $this->hasChildSetting('restrict_child_blocks') ? 'restrict_child_blocks' : null,
                    $this->hasChildSetting('child_block_whitelist') ? 'child_block_whitelist' : null,
                    $this->hasChildSetting('child_tag_whitelist') ? 'child_tag_whitelist' : null,
                    $this->hasChildSetting('default_child_block') ? 'default_child_block' : null,
                    $this->hasChildSetting('child_sort_by') ? 'child_sort_by' : null,
                    $this->hasChildSetting('child_sort_direction') ? 'child_sort_direction' : null,
                ]));
                $errors = app(ContentI18nValidator::class)->validate(
                    $space,
                    $validator->safe()->all(),
                    $content,
                    $childSettingKeys,
                );

                foreach ($errors as $path => $message) {
                    $validator->errors()->add($path, $message);
                }
            },
        ];
    }

    private function hasChildSetting(string $key): bool
    {
        return Arr::has($this->input('settings', []), $key);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function translationRules(): array
    {
        return [
            'translations' => 'sometimes|array',
            'translations.*' => 'array',
            'translations.*.id' => 'sometimes|string',
            'translations.*.external_id' => ['sometimes', 'nullable', 'string', 'max:36'],
            'translations.*.name' => 'sometimes|required|string|max:100',
            'translations.*.slug' => 'sometimes|required|string|max:70',
            'translations.*.settings' => 'nullable|array',
            ...ContentSettings::toValidator('translations.*.settings'),
            'translations.*.block_id' => 'sometimes|required|string',
            'translations.*.parent_id' => 'nullable|string',
            'translations.*.position' => 'sometimes|integer|min:0',
            'translations.*.language_iso' => 'sometimes|required|string|min:2|max:5',
            'translations.*.content' => 'nullable|array',
            'translations.*.force' => 'sometimes|boolean',
        ];
    }
}
