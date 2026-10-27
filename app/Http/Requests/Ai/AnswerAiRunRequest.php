<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class AnswerAiRunRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'answers' => ['present', 'array'],
            'answers.*' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
