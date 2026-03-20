<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;

class ListTeamsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('include_space_context')) {
            $value = $this->input('include_space_context');
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($normalized !== null) {
                $this->merge([
                    'include_space_context' => $normalized,
                ]);
            }
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'include_space_context' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string'],
            'name' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'string'],
            'created_at' => ['nullable', 'string'],
            'updated_at' => ['nullable', 'string'],
        ];
    }
}
