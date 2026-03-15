<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\Automation\Concerns\ValidatesTriggerDefinition;
use App\Models\Management\AutomationAction;
use App\Services\Automation\Enums\TriggerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutomationCreateRequest extends FormRequest
{
    use ValidatesTriggerDefinition;

    public function rules(): array
    {
        $spaceId = (string) ($this->route('space')?->id ?? $this->route('space'));

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'action_id' => [
                'required',
                'string',
                Rule::exists((new AutomationAction())->getTable(), 'id')
                    ->where(fn ($query) => $query->where('space_id', $spaceId)->whereNull('deleted_at')),
            ],
            'trigger' => ['required', 'array'],
            'trigger.type' => ['required', 'string', Rule::in(array_column(TriggerType::cases(), 'value'))],
            'trigger.config' => ['nullable', 'array'],
            'is_active' => ['boolean'],
            'execution_limit' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $this->validateTriggerDefinition(
                    $validator,
                    (string) $this->input('trigger.type'),
                    (array) $this->input('trigger.config', []),
                );
            },
        ];
    }
}
