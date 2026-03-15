<?php

namespace App\Http\Requests\AutomationAction;

use App\Http\Requests\Automation\Concerns\ValidatesActionDefinition;
use App\Services\Automation\Enums\ActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutomationActionCreateRequest extends FormRequest
{
    use ValidatesActionDefinition;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', 'string', Rule::in(array_column(ActionType::cases(), 'value'))],
            'config' => ['required', 'array'],
            'secrets' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $this->validateActionDefinition(
                    $validator,
                    (string) $this->input('type'),
                    (array) $this->input('config', []),
                    (array) $this->input('secrets', []),
                );
            },
        ];
    }
}
