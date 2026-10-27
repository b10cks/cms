<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

/** Shape only; `SkillParser::parseUpload()` validates the skill itself. */
class SaveAiSkillRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'files' => [$this->isMethod('post') ? 'required' : 'sometimes', 'array', 'min:1'],
            'files.*.path' => ['required', 'string'],
            'files.*.content' => ['present', 'nullable', 'string'],
        ];
    }
}
