<?php

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;

class ContentBlockTranslationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['string', 'max:36', 'distinct'],
        ];
    }
}
