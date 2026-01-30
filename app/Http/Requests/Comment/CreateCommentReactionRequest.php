<?php

namespace App\Http\Requests\Comment;

use Illuminate\Foundation\Http\FormRequest;

class CreateCommentReactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'emoji' => [
                'required',
                'string',
                'max:50',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'emoji.required' => 'An emoji code is required.',
            'emoji.string' => 'The emoji must be a valid string.',
            'emoji.max' => 'The emoji code cannot be longer than 50 characters.',
        ];
    }
}
