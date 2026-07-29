<?php

namespace App\Http\Requests\Token;

use App\Services\TokenAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'abilities' => 'sometimes|array|min:1',
            'abilities.*' => 'string|max:100',
            'expires_at' => 'nullable|date|after:now',
            'execution_limit' => 'nullable|integer|min:1',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $abilities = $this->input('abilities');
                if (is_array($abilities) && ! TokenAbility::fromArray($abilities)->validate()) {
                    $validator->errors()->add(
                        'abilities',
                        'Abilities must use the "resource:action" format with a known action.',
                    );
                }
            },
        ];
    }
}
