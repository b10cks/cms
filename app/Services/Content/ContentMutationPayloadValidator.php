<?php

namespace App\Services\Content;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Models\Space\Content;
use App\Models\Space\ContentSettings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ContentMutationPayloadValidator
{
    use ExternalIdValidation;

    /**
     * @return array<string, mixed>
     */
    public function validate(array $data, ?Content $content = null, bool $publish = false): array
    {
        $contentId = $content?->id;
        $parentId = $data['parent_id'] ?? $content?->parent_id;
        $languageIso = $data['language_iso'] ?? $content?->language_iso;
        $connectionName = (new Content)->getConnectionName();

        $rules = [
            'id' => 'sometimes|string',
            'external_id' => $this->externalIdRule(Content::class, $contentId),
            'name' => 'sometimes|required|string|max:100',
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:75',
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
        ];

        if ($publish) {
            $rules['message'] = 'sometimes|string|max:255';
            $rules['published_at'] = 'sometimes|nullable|date';
        }

        return Validator::make($data, $rules, [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
        ])->validate();
    }
}
