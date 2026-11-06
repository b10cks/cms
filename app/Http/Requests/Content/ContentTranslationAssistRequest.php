<?php

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;

class ContentTranslationAssistRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', 'max:20'],
            'unit' => ['required', 'string', 'max:500'],
            'text' => ['sometimes', 'nullable', 'string', 'max:50000'],
        ];
    }
}
