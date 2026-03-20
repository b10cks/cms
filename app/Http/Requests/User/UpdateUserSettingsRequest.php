<?php

namespace App\Http\Requests\User;

use App\Models\User\UserSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return UserSettings::toValidator(partial: true);
    }
}
