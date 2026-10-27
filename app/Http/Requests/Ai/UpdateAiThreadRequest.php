<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

/** Rename, share or archive a thread; the controller checks each field against the policy. */
class UpdateAiThreadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'shared' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }
}
