<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class StoreSpaceRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'level' => ['required', 'integer', 'between:1,299'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string'],
        ];
    }
}
