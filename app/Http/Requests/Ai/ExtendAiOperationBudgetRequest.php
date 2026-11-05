<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class ExtendAiOperationBudgetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The new total budget, above what was spent.
            'max_usd' => ['required', 'numeric', 'min:0.01', 'max:1000'],
        ];
    }
}
