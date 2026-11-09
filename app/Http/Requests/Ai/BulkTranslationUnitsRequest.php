<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkTranslationUnitsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'unit_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'unit_ids.*' => ['string', 'max:64'],
            'action' => ['required', Rule::in(['delete'])],
        ];
    }
}
