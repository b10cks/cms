<?php

namespace App\Http\Requests\Ai;

use App\Models\Space\AiRun;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\EntryScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartAiRunRequest extends FormRequest
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
            'prompt' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'context' => ['sometimes', 'array'],
            'config_id' => ['sometimes', 'nullable', 'string'],
            'conversation_id' => ['sometimes', 'nullable', 'string'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'background' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:'.(int) config('ai.attachments.max_files')],
            'attachments.*' => ['string', 'distinct', 'max:40'],
            'targets' => ['sometimes', 'nullable', 'array'],
            'targets.type' => ['required_with:targets', Rule::in(ChangeTargets::TYPES)],
            'targets.ids' => ['required_with:targets', 'array', 'min:1', 'max:'.EntryScope::MAX_ENTRIES],
            'targets.ids.*' => ['string', 'max:40'],
        ];
    }
}
