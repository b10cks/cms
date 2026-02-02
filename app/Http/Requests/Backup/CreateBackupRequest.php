<?php

namespace App\Http\Requests\Backup;

use Illuminate\Foundation\Http\FormRequest;

class CreateBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'to' => 'required|array|min:1',
            'to.*' => 'required|email',
            'password' => 'nullable|string|min:8|max:100',
            'expires_at' => 'required|date|after:now',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('validation.backup.name_required'),
            'to.required' => __('validation.backup.recipients_required'),
            'to.*.email' => __('validation.backup.invalid_email'),
            'expires_at.after' => __('validation.backup.expires_after_now'),
        ];
    }
}
