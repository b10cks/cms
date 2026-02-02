<?php

namespace App\Http\Requests\Backup;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'expires_at' => 'sometimes|required|date|after:now',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('validation.backup.name_required'),
            'expires_at.after' => __('validation.backup.expires_after_now'),
        ];
    }
}
