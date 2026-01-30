<?php

namespace App\Http\Requests\Comment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => 'sometimes|required|string|min:1|max:10000',
            'item_id' => 'nullable|ulid',
            'field' => 'nullable|string|max:100',
            'position' => 'nullable|array',
            'position.x' => 'nullable|integer|min:0',
            'position.y' => 'nullable|integer|min:0',
        ];
    }
}
