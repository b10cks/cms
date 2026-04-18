<?php

namespace App\Http\Requests\Content;


class PublishContentRequest extends UpsertContentRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'message' => 'sometimes|string|max:255',
            'published_at' => 'sometimes|date',
            'translations.*.message' => 'sometimes|string|max:255',
            'translations.*.published_at' => 'sometimes|date',
        ];
    }
}
