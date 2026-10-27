<?php

namespace App\Http\Requests\Ai;

use App\Enums\AiKnowledgeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAiKnowledgeDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug' => [$creating ? 'required' : 'sometimes', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'type' => [$creating ? 'required' : 'sometimes', Rule::enum(AiKnowledgeType::class)],
            'enabled' => ['sometimes', 'boolean'],
            'draft' => ['sometimes', 'nullable', 'string', 'max:500000'],
        ];
    }
}
