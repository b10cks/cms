<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class DecideAiChangesetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'item_ids' => ['sometimes', 'nullable', 'array', 'max:1000'],
            'item_ids.*' => ['string', 'max:40'],
            'overwrite' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<string>|null
     */
    public function itemIds(): ?array
    {
        $ids = $this->input('item_ids');

        return \is_array($ids) ? array_values($ids) : null;
    }
}
