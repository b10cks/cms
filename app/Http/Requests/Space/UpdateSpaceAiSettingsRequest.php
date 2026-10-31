<?php

namespace App\Http\Requests\Space;

use App\Models\Management\SpaceSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSpaceAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The `ai.*` rules of the space settings, unprefixed: this endpoint takes
     * the AI section itself as its body (`{ enabled, model, favourites }`).
     */
    public function rules(): array
    {
        $rules = [];
        foreach (SpaceSettings::validationRules(partial: true) as $key => $rule) {
            if (str_starts_with($key, 'ai.')) {
                $rules[substr($key, 3)] = $rule;
            }
        }

        return $rules;
    }
}
