<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => [
                'required',
                'string',
                'min:1',
                'max:500',
                [
                    'description' => 'Free-text search query used to find matching content entries.',
                    'example' => 'homepage',
                ],
            ],
            'limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
                [
                    'description' => 'Maximum number of search results to return.',
                    'example' => 20,
                ],
            ],
            'offset' => [
                'sometimes',
                'integer',
                'min:0',
                [
                    'description' => 'Number of matching results to skip before returning items.',
                    'example' => 0,
                ],
            ],
            'language' => [
                'sometimes',
                'string',
                'min:2',
                'max:5',
                [
                    'description' => 'Preferred language ISO code used for result resolution.',
                    'example' => 'en',
                ],
            ],
        ];
    }
}
