<?php

namespace App\Http\Requests\Content;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Models\Space\Content;
use App\Services\Content\ContentI18nValidator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
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
                'max:50',
                Rule::unique($connectionName.'.contents', 'slug')
                    ->when($languageIso, fn ($query) => $query->where('language_iso', $languageIso))
                    ->when($parentId, fn ($query) => $query->where('parent_id', $parentId))
                    ->whereNull('deleted_at')
                    ->ignore($contentId),
            ],
            'settings' => 'nullable|array',
            'settings.disablePreview' => 'nullable|boolean',
            'settings.i18n_mode_override' => ['nullable', 'string', Rule::in(['inherit', 'overlay', 'independent'])],
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
            'i18n_parent_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists($connectionName.'.contents', 'id')
                    ->whereNull('deleted_at'),
            ],
            'language_iso' => 'sometimes|required|string|min:2|max:5',
            'content' => 'nullable|array',
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
                $space = $this->route('space');
                if (! $space) {
                    return;
                }

                $content = $this->route('content');
                $errors = app(ContentI18nValidator::class)->validate($space, $this->validated(), $content);

                foreach ($errors as $path => $message) {
                    $validator->errors()->add($path, $message);
                }
            },
        ];
    }
}
