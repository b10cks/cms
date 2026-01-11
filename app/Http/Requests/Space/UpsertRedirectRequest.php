<?php

namespace App\Http\Requests\Space;

use App\Models\Space\Redirect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertRedirectRequest extends FormRequest
{

    public function rules(): array
    {
        $redirect = $this->route('redirect');
        $uniqueRule = Rule::unique(new Redirect()->getConnectionName() . '.redirects', 'source');

        if ($redirect) {
            $uniqueRule->ignore($redirect->id);
        }

        return [
            'external_id' => 'sometimes|nullable|string|max:36',
            'source' => ['required', 'string', 'max:255', $uniqueRule],
            'target' => ['required', 'string', 'max:255'],
            'status_code' => ['sometimes', 'required', 'integer', Rule::in([301, 302, 303, 307, 308])],
        ];
    }

    public function messages(): array
    {
        return [
            'source.required' => 'The source path is required.',
            'source.unique' => 'This source path already exists in a redirect.',
            'target.required' => 'The target path is required.',
            'status_code.in' => 'The status code must be a valid HTTP redirect code (301, 302, 303, 307, 308).',
        ];
    }
}
