<?php

namespace App\Http\Requests\Release;

use Illuminate\Foundation\Http\FormRequest;

class StoreReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'external_id' => 'nullable|string|max:36',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'settings' => 'nullable|array',
            'publish_at' => 'nullable|date|after_or_equal:now',
        ];
    }
}
