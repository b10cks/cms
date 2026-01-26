<?php

namespace App\Http\Requests\Content;


class PublishContentRequest extends UpsertContentRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'message' => 'sometimes|string|max:255',
        ];
    }
}
