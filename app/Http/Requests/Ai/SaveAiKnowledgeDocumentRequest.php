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
            'description' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:200'],
            'slug' => [$creating ? 'required' : 'sometimes', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'type' => [$creating ? 'required' : 'sometimes', Rule::enum(AiKnowledgeType::class)],
            'locale' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/'],
            'enabled' => ['sometimes', 'boolean'],
            'draft' => ['sometimes', 'nullable', 'string', 'max:500000'],
        ];
    }
}
