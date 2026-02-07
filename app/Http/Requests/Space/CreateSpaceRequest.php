<?php

namespace App\Http\Requests\Space;

use App\Services\Space\SpaceI18nSettingsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSpaceRequest extends FormRequest
{
    protected ?array $normalizedSettings = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization will be handled by policies/middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:50|regex:/^[a-z0-9\-]+$/|unique:spaces,slug,NULL,id,team_id,'.$this->input('team_id'),
            'icon' => 'nullable|string|max:50',
            'team_id' => 'nullable|string|max:26',
            'color' => 'nullable|string|max:7|regex:/^#[a-fA-F0-9]{6}$/',
            'badge' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'settings' => 'nullable|array',
            'settings.default_language' => 'nullable|string|min:2|max:5',
            'settings.i18n_mode' => ['nullable', 'string', Rule::in(['overlay', 'independent'])],
            'settings.languages' => 'nullable|array',
            'settings.languages.*.code' => 'required|string|min:2|max:5',
            'settings.languages.*.name' => 'required|string|max:100',
            'settings.languages.*.fallback_language' => 'nullable|string|min:2|max:5',
            'settings.asset_fields' => 'nullable|array',
            'settings.asset_fields.*.key' => 'required|string|max:100',
            'settings.asset_fields.*.label' => 'required|string|max:100',
            'settings.asset_fields.*.required' => 'required|boolean',
            'plan_id' => 'nullable|string|exists:plans,id',
            'blueprint_id' => [
                'nullable',
                'string',
                'max:26',
                Rule::exists('space_blueprints', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
            'blueprint_id.exists' => __('validation.blueprint.invalid'),
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $fieldKeys = array_filter(array_map(
                    fn (array $field): ?string => $field['key'] ?? null,
                    $this->input('settings.asset_fields', [])
                ));

                if (count($fieldKeys) !== count(array_unique($fieldKeys))) {
                    $validator->errors()->add('settings.asset_fields', 'Asset field keys must be unique.');
                }

                if (! $this->has('settings')) {
                    return;
                }

                $service = app(SpaceI18nSettingsService::class);
                $this->normalizedSettings = $service->normalize($this->input('settings', []));

                foreach ($service->validate($this->normalizedSettings) as $path => $message) {
                    $validator->errors()->add($path, $message);
                }
            },
        ];
    }

    public function validated($key = null, $default = null): array|string|null
    {
        $validated = parent::validated();

        if (\array_key_exists('settings', $validated)) {
            $validated['settings'] = $this->normalizedSettings
                ?? app(SpaceI18nSettingsService::class)->normalize($validated['settings'] ?? []);
        }

        return $key === null ? $validated : data_get($validated, $key, $default);
    }
}
