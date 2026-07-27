<?php

namespace App\Http\Requests\Space;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Models\Space\FieldPlugin;
use App\Rules\LoopbackUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateFieldPluginRequest extends FormRequest
{
    use ExternalIdValidation;

    public const MAX_CODE_SIZE = 1572864; // 1.5 MB

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'external_id' => $this->externalIdRule(FieldPlugin::class),
            'name' => 'required|string|max:100',
            'handle' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9\-]+$/',
                Rule::unique(new FieldPlugin()->getConnectionName().'.field_plugins', 'handle'),
            ],
            ...self::commonRules(),
        ];
    }

    /** Rules shared with UpdateFieldPluginRequest. */
    public static function commonRules(): array
    {
        return [
            'description' => 'nullable|string',
            'dev_mode' => 'boolean',
            // A dev bundle is framed by the editor, so an arbitrary host here
            // would mean arbitrary script in everyone else's editor.
            'dev_url' => ['nullable', 'url:http,https', 'max:2048', new LoopbackUrl],
            'code' => 'nullable|string|max:'.self::MAX_CODE_SIZE,
            'manifest' => 'nullable|array',
            'manifest.options' => 'nullable|array',
            'manifest.options.*.key' => 'required|string|max:64',
            'manifest.options.*.label' => 'nullable|string|max:100',
            'manifest.options.*.default' => 'nullable|string',
            'manifest.height' => 'nullable|integer|min:50|max:1200',
            'is_active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'handle.regex' => 'The handle may only contain lowercase letters, numbers, and hyphens.',
        ];
    }
}
