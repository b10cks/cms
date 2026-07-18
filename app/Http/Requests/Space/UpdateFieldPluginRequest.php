<?php

namespace App\Http\Requests\Space;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Models\Space\FieldPlugin;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFieldPluginRequest extends FormRequest
{
    use ExternalIdValidation;

    public function authorize(): bool
    {
        return true;
    }

    // The handle is deliberately not updatable: block schemas reference it.
    public function rules(): array
    {
        return [
            'external_id' => $this->externalIdRule(FieldPlugin::class, $this->route('field_plugin')?->id),
            'name' => 'sometimes|string|max:100',
            ...CreateFieldPluginRequest::commonRules(),
        ];
    }
}
