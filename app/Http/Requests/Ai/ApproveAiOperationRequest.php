<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class ApproveAiOperationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The hash of the plan the editor reviewed, from the operation's `plan_hash`.
            'plan_hash' => ['required', 'string', 'size:64'],
            // The `counts.scope_hash` of the preview the editor approved.
            'scope_hash' => ['required', 'string', 'size:64'],
            'max_usd' => ['required', 'numeric', 'min:0.01', 'max:1000'],
        ];
    }
}
