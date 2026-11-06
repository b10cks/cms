<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The draft plan as the editor edited it; validated per kind by OperationPlan.
 *
 * @requestProperty plan shape=@AiOperationPlan
 */
class UpdateAiOperationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'plan' => ['required', 'array'],
        ];
    }
}
