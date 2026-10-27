<?php

namespace App\Http\Requests\Ai;

use App\Models\Management\AiRun;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
