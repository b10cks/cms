<?php

namespace App\Http\Requests\Ai;

use App\Models\Management\AiRun;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\EntryScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiPreflightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [AiRun::class, $this->route('space'), (string) $this->input('surface')]) ?? false;
    }

    public function rules(): array
    {
        return [
            'surface' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9:_-]+$/'],
            'skill' => ['sometimes', 'nullable', 'string', 'max:64'],
            'config_id' => ['sometimes', 'nullable', 'string'],
            'scope' => ['required', 'array'],
            'scope.type' => ['required', Rule::in(ChangeTargets::TYPES)],
            'scope.ids' => ['sometimes', 'array', 'max:'.EntryScope::MAX_ENTRIES],
            'scope.ids.*' => ['string', 'max:40'],
            'scope.filters' => ['sometimes', 'array'],
        ];
    }
}
