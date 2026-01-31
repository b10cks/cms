<?php

namespace App\Http\Requests\Comment;

use App\Models\Space\Comment;
use App\Models\Space\ContentVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'parent_id' => [
                'nullable',
                'ulid',
                Rule::exists(Comment::class, 'id'),
            ],
            'content_version_id' => [
                'nullable',
                'ulid',
                Rule::exists(new ContentVersion()->getConnectionName() . '.content_versions', 'id')
            ],
            'body' => 'required|string|min:1|max:10000',
            'item_id' => 'nullable|ulid',
            'field' => 'nullable|string|max:100',
            'position' => 'nullable|array',
            'position.x' => 'nullable|integer|min:0',
            'position.y' => 'nullable|integer|min:0',
        ];
    }
}
