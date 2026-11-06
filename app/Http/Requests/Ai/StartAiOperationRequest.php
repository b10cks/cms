<?php

namespace App\Http\Requests\Ai;

use App\Enums\AiOperationKind;
use App\Models\Space\AiRun;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\EntryScope;
use App\Services\Ai\Schedules\ScheduleRunner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StartAiOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $surface = ScheduleRunner::SURFACES[$this->targetType()] ?? 'bulk:content';

        return $this->user()?->can('create', [AiRun::class, $this->route('space'), $surface]) ?? false;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(AiOperationKind::class)],
            'skill' => ['sometimes', 'nullable', 'string', 'max:64'],
            'prompt' => ['required', 'string', 'max:20000'],
            'config_id' => ['sometimes', 'nullable', 'string'],
            'conversation_id' => ['sometimes', 'nullable', 'string'],
            'scope' => ['required', 'array'],
            'scope.type' => ['required', Rule::in(ChangeTargets::TYPES)],
            'scope.ids' => ['sometimes', 'nullable', 'array', 'min:1', 'max:1000'],
            'scope.ids.*' => ['string', 'max:40'],
            'scope.root_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'scope.recursive' => ['sometimes', 'boolean'],
            'scope.filters' => ['sometimes', 'array:'.implode(',', EntryScope::RECURSIVE_FILTERS)],
            'scope.filters.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $kind = AiOperationKind::tryFrom((string) $this->input('kind'));
            $expected = $kind?->targetType();

            if ($expected !== null && $this->input('scope.type') !== $expected) {
                $validator->errors()->add('scope.type', "A {$kind->value} operation works on {$expected} entries.");
            }
        }];
    }

    public function targetType(): string
    {
        return (string) $this->input('scope.type');
    }
}
