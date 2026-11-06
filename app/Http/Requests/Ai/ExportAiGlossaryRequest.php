<?php

namespace App\Http\Requests\Ai;

use App\Services\Ai\Knowledge\GlossaryTransfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportAiGlossaryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'format' => ['sometimes', Rule::in($this->boolean('template') ? GlossaryTransfer::TEMPLATE_FORMATS : GlossaryTransfer::FORMATS)],
            'template' => ['sometimes', 'boolean'],
        ];
    }
}
