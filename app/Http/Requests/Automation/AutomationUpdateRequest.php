<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\Automation\Concerns\ValidatesTriggerDefinition;
use App\Models\Management\Automation;
use App\Models\Management\AutomationAction;
use App\Services\Automation\Enums\TriggerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutomationUpdateRequest extends FormRequest
{
    use ValidatesTriggerDefinition;

    public function rules(): array
    {
        $spaceId = (string) ($this->route('space')?->id ?? $this->route('space'));

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'action_id' => [
                'sometimes',
                'required',
                'string',
                Rule::exists((new AutomationAction())->getTable(), 'id')
                    ->where(fn ($query) => $query->where('space_id', $spaceId)->whereNull('deleted_at')),
            ],
            'trigger' => ['sometimes', 'required', 'array'],
            'trigger.type' => ['required_with:trigger', 'string', Rule::in(array_column(TriggerType::cases(), 'value'))],
            'trigger.config' => ['nullable', 'array'],
            'is_active' => ['boolean'],
            'execution_limit' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                /** @var Automation|null $automation */
                $automation = $this->route('automation');

                $trigger = $this->input('trigger');
                $typeValue = (string) data_get($trigger, 'type', $automation?->trigger_type?->value);
                $config = (array) data_get($trigger, 'config', $automation?->trigger_config ?? []);

                $this->validateTriggerDefinition($validator, $typeValue, $config);
            },
        ];
    }
}
