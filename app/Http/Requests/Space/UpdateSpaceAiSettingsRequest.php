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

    public function rules(): array
    {
        return SpaceSettings::toValidator('ai', true);
    }
}
