<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpaceRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/'],
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'level' => ['sometimes', 'integer', 'between:1,299'],
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string'],
        ];
    }
}
