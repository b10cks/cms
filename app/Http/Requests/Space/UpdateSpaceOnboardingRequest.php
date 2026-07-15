<?php

namespace App\Http\Requests\Space;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpaceOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorized in the controller against the space route binding.
        return true;
    }

    public function rules(): array
    {
        return [
            'dismissed' => ['required', 'boolean'],
        ];
    }
}
