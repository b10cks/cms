<?php

namespace App\Http\Requests\Release;

use Illuminate\Foundation\Http\FormRequest;

class AssignContentVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version_ids' => 'required_without:content_ids|array',
            'version_ids.*' => 'required|string',
            // Entries instead of versions: each contributes its current draft.
            'content_ids' => 'required_without:version_ids|array|max:500',
            'content_ids.*' => 'required|string',
        ];
    }
}
