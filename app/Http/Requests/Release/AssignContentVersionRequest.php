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
            'version_ids' => 'required|array',
            'version_ids.*' => 'required|string',
        ];
    }
}
