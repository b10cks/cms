<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class CheckAiGlossaryTextRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:50000'],
            'source_locale' => ['required', 'string', 'max:20'],
            'target_locale' => ['sometimes', 'nullable', 'string', 'max:20'],
            'scope' => ['sometimes', 'in:glossary,space'],
            'output' => ['sometimes', 'nullable', 'string', 'max:50000'],
        ];
    }
}
