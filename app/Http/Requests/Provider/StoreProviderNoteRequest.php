<?php

namespace App\Http\Requests\Provider;

use Illuminate\Foundation\Http\FormRequest;

class StoreProviderNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_root;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'icon' => ['nullable', 'string', 'max:100'],
            'url' => ['nullable', 'url', 'max:2048'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'content' => ['nullable', 'string'],
            'is_pinned' => ['sometimes', 'boolean'],
        ];
    }
}
