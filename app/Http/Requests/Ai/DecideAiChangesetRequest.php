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
            // Reviewer edits of text proposals, item ID => accepted text.
            'values' => ['sometimes', 'array', 'max:1000'],
            'values.*' => ['string', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function editedValues(): array
    {
        return array_filter((array) $this->input('values', []), 'is_string');
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
