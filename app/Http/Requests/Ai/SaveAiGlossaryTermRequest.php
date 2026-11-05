<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One language of a row in the older term shape. The writer owns the note's limit, because
 * only it knows the note a row already holds.
 */
class SaveAiGlossaryTermRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'source_locale' => [$creating ? 'required' : 'sometimes', 'string', 'max:20'],
            'term' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'translations' => ['sometimes', 'nullable', 'array'],
            'translations.*' => ['nullable', 'string', 'max:255'],
            'case_sensitive' => ['sometimes', 'boolean'],
            'do_not_translate' => ['sometimes', 'boolean'],
            'forbidden' => ['sometimes', 'nullable', 'array', 'max:20'],
            'forbidden.*' => ['string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string'],
            'source_aliases' => ['sometimes', 'nullable', 'array', 'max:100'],
            'source_aliases.*' => ['string', 'max:255'],
        ];
    }
}
