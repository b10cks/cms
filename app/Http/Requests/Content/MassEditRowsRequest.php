<?php

namespace App\Http\Requests\Content;

use App\Http\Filters\Mgmt\ContentMassEditFilter;
use Illuminate\Foundation\Http\FormRequest;

class MassEditRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Everything listed here except `fields`, `languages`, `page` and `per_page` is
     * handed to {@see ContentMassEditFilter} — keep the two in sync,
     * values use its `operator:value` syntax.
     */
    public function rules(): array
    {
        $rules = [
            'fields' => ['required', 'string'],
            'languages' => ['sometimes', 'nullable', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string'],

            'name' => ['sometimes', 'nullable', 'string'],
            'slug' => ['sometimes', 'nullable', 'string'],
            'full_slug' => ['sometimes', 'nullable', 'string'],
            'external_id' => ['sometimes', 'nullable', 'string'],
            'block_id' => ['sometimes', 'nullable', 'string'],
            'published' => ['sometimes', 'nullable', 'string'],
            'published_at' => ['sometimes', 'nullable', 'string'],
            'created_at' => ['sometimes', 'nullable', 'string'],
            'updated_at' => ['sometimes', 'nullable', 'string'],
        ];

        // Dynamic per-field value filters, e.g. `field_title=like:Home`.
        foreach (array_keys($this->all()) as $key) {
            if (\is_string($key) && str_starts_with($key, 'field_')) {
                $rules[$key] = ['nullable', 'string'];
            }
        }

        return $rules;
    }

    /**
     * @return array<int, string>
     */
    public function getFieldKeys(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->input('fields')))));
    }

    /**
     * @return array<int, string>|null
     */
    public function getLanguageFilter(): ?array
    {
        $value = trim((string) $this->input('languages', ''));

        if ($value === '') {
            return null;
        }

        $languages = array_values(array_filter(array_map('trim', explode(',', $value))));

        return $languages === [] ? null : $languages;
    }
}
