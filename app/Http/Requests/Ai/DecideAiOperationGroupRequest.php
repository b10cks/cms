<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class DecideAiOperationGroupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // A `key` from the operation's review groups.
            'group' => ['required', 'string', 'max:700'],
        ];
    }
}
