<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class UploadAiKnowledgeSourceRequest extends FormRequest
{
    /** Upload limit in kilobytes, shared with the MCP file arguments. */
    public const MAX_KB = 20480;

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.self::MAX_KB, 'mimes:pdf,docx,md,txt'],
        ];
    }
}
