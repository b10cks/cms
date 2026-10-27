<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class ImportAiGlossaryRequest extends FormRequest
{
    /** Upload limit in kilobytes, shared with the MCP file arguments. */
    public const MAX_KB = 10240;

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.self::MAX_KB, 'extensions:csv,xlsx,tbx,xml'],
        ];
    }
}
