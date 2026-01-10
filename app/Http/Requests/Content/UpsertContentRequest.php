<?php

namespace App\Http\Requests\Content;

use App\Models\Space\Content;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertContentRequest extends FormRequest
{
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $contentId = $this->route('content');
        $blockId = $this->route('block') ?? $this->input('block_id');

        $connectionName = new Content()->getConnectionName();

        return [
            'name' => 'sometimes|required|string|max:100',
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique($connectionName . '.contents', 'slug')
                    ->where(function ($query) {
                        return $query->where('parent_id', $this->input('parent_id', null));
                    })
                    ->ignore($contentId),
            ],
            'settings' => 'nullable|array',
            'block_id' => [
                'sometimes',
                'required',
                Rule::exists($connectionName . '.contents', 'id')
            ],
            'parent_id' => [
                'nullable',
                'string',
                Rule::exists($connectionName . '.contents', 'id')
            ],
            'i18n_parent_id' => [
                'sometimes',
                Rule::exists($connectionName . '.contents', 'id')
            ],
            'language_iso' => 'sometimes|required|string|size:2,5',
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
}
