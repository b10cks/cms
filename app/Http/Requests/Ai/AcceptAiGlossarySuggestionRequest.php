<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class AcceptAiGlossarySuggestionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'glossary_id' => ['required', 'string', 'max:40'],
        ];
    }
}
