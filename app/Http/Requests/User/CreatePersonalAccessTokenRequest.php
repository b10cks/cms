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
            // Personal access tokens act with the owner's full permissions;
            // nothing enforces narrower abilities, so refuse to store them.
            'abilities' => 'nullable|array',
            'abilities.*' => 'string|in:*',
            'expires_at' => 'nullable|date|after:now',
        ];
    }
}
