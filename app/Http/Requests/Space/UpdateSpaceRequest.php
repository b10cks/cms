<?php

namespace App\Http\Requests\Space;

use App\Models\Management\SpaceSettings;
use App\Services\Space\SpaceI18nSettingsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSpaceRequest extends FormRequest
{
    /**
     * Settings written only through their own, more strictly gated endpoint.
     */
    private const SEPARATELY_GATED_SETTINGS = ['ai'];

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
        $space = $this->route('space');

        return [
            'name' => 'sometimes|required|string|max:100',
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9\-]+$/',
                Rule::unique('spaces', 'slug')->ignore($space),
            ],
            'icon' => 'sometimes|nullable|string|max:50',
            'color' => 'sometimes|nullable|string|max:7|regex:/^#[a-fA-F0-9]{6}$/',
            'badge' => 'sometimes|nullable|string|max:50',
            'description' => 'sometimes|nullable|string',
            'settings' => 'sometimes|nullable|array',

            // Every settings sub-key needs a rule, and not only to be checked:
            // once any child rule exists, validated() returns just the children
            // it knows about and drops the rest of the array. Listing a handful
            // by hand therefore meant `environments`, `visual_editor`,
            // `search_driver`, `slug_strategy`, `asset_fields` and the others
            // were silently discarded on every save. SpaceSettings owns the
            // shape, so it owns the rules.
            ...SpaceSettings::toValidator('settings', partial: true),

            'state' => [
                'sometimes',
                'string',
                Rule::in(['draft', 'live', 'archived', 'error']),
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
            'state.in' => 'The state must be one of: draft, live, archived, error.',
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

                $sitemapBlocks = array_filter(array_map(
                    fn (array $type): ?string => isset($type['block']) ? strtolower((string) $type['block']) : null,
                    $this->input('settings.sitemap.types', [])
                ));

                if (count($sitemapBlocks) !== count(array_unique($sitemapBlocks))) {
                    $validator->errors()->add('settings.sitemap.types', 'Sitemap block mappings must be unique.');
                }

                foreach ($this->input('settings.sitemaps', []) as $index => $sitemap) {
                    if (! \is_array($sitemap)) {
                        continue;
                    }

                    $blocks = array_filter(array_map(
                        fn (mixed $type): ?string => \is_array($type) && isset($type['block'])
                            ? strtolower((string) $type['block'])
                            : null,
                        \is_array($sitemap['types'] ?? null) ? $sitemap['types'] : [],
                    ));

                    if (count($blocks) !== count(array_unique($blocks))) {
                        $validator->errors()->add(
                            "settings.sitemaps.{$index}.types",
                            'Sitemap block mappings must be unique per sitemap.',
                        );
                    }
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
            // Normalizing the validated subset rather than the raw input is
            // what keeps unlisted sub-keys out: they never appear here, so they
            // cannot be written by a caller who guessed a key name.
            $validated['settings'] = app(SpaceI18nSettingsService::class)
                ->normalize($validated['settings'] ?? []);

            $validated['settings'] = $this->withoutSeparatelyGatedSettings($validated['settings']);
        }

        return $key === null ? $validated : data_get($validated, $key, $default);
    }

    /**
     * Drop settings that belong to a differently-permissioned endpoint.
     *
     * `space.update` could otherwise write AI configuration that its own
     * endpoint gates behind `ai.manage`. Those keys keep whatever value the
     * space already has.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function withoutSeparatelyGatedSettings(array $settings): array
    {
        $space = $this->route('space');
        $current = $space?->settings?->toArray() ?? [];

        foreach (self::SEPARATELY_GATED_SETTINGS as $gated) {
            unset($settings[$gated]);

            if (\array_key_exists($gated, $current)) {
                $settings[$gated] = $current[$gated];
            }
        }

        return $settings;
    }
}
