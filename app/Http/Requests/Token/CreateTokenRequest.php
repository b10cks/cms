<?php

namespace App\Http\Requests\Token;

use Illuminate\Foundation\Http\FormRequest;

class CreateTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
//            'abilities' => 'required|array',
//            'abilities.*' => 'string',
            'expires_at' => 'nullable|date|after:now',
            'execution_limit' => 'nullable|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
//            'abilities.required' => 'At least one ability must be specified.',
//            'expires_at.after' => 'The expiration date must be a future date.',
        ];
    }
}
