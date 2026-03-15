<?php

namespace App\Http\Requests\AutomationAction;

use App\Http\Requests\Automation\Concerns\ValidatesActionDefinition;
use App\Models\Management\AutomationAction;
use App\Services\Automation\Enums\ActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutomationActionUpdateRequest extends FormRequest
{
    use ValidatesActionDefinition;

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['sometimes', 'required', 'string', Rule::in(array_column(ActionType::cases(), 'value'))],
            'config' => ['sometimes', 'required', 'array'],
            'secrets' => ['nullable', 'array'],
            'clear_secret_keys' => ['nullable', 'array'],
            'clear_secret_keys.*' => ['string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                /** @var AutomationAction|null $action */
                $action = $this->route('automation_action');

                $typeValue = (string) $this->input('type', $action?->type?->value);
                $config = (array) $this->input('config', $action?->config ?? []);
                $secrets = (array) $this->input('secrets', []);

                $this->validateActionDefinition($validator, $typeValue, $config, $secrets);
            },
        ];
    }
}
