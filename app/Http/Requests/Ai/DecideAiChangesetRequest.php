<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @requestProperty values shape=map:string Reviewer edits of text proposals, item ID => accepted text.
 * @requestProperty overrides shape=map:@AiFindingOverride[] Reasons for keeping deterministic findings, item ID => findings.
 */
class DecideAiChangesetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'item_ids' => ['sometimes', 'nullable', 'array', 'max:1000'],
            'item_ids.*' => ['string', 'max:40'],
            'overwrite' => ['sometimes', 'boolean'],
            // A bulk decision: items that need an individual look stay open.
            'bulk' => ['sometimes', 'boolean'],
            // Reviewer edits of text proposals, item ID => accepted text.
            'values' => ['sometimes', 'array', 'max:1000'],
            'values.*' => ['string', 'max:65535'],
            // Reasons for keeping deterministic glossary findings, item ID => findings.
            'overrides' => ['sometimes', 'array', 'max:1000'],
            'overrides.*' => ['array', 'max:100'],
            'overrides.*.*.finding_id' => ['required', 'string', 'max:64'],
            'overrides.*.*.reason' => ['required', 'string', 'max:2000'],
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
     * @return array<string, list<array{finding_id: string, reason: string}>>
     */
    public function overrides(): array
    {
        return (array) ($this->validated('overrides') ?? []);
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
