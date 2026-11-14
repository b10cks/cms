<?php

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;

class ContentTranslationReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', 'max:20'],
            'ai' => ['required_without:reviewed', 'array', 'max:500'],
            'ai.*' => ['string', 'max:500'],
            'reviewed' => ['required_without:ai', 'array', 'max:500'],
            'reviewed.*' => ['string', 'max:500'],
        ];
    }
}
