<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class CreatePersonalAccessTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'abilities' => 'nullable|array',
            'abilities.*' => 'string|max:100',
            'expires_at' => 'nullable|date|after:now',
        ];
    }
}
