<?php

namespace App\Http\Requests\BlockVersion;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBlockVersionCommitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'commit_message' => 'required|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'commit_message.required' => __('validation.block_version.commit_message_required'),
            'commit_message.max' => __('validation.block_version.commit_message_max'),
        ];
    }
}
