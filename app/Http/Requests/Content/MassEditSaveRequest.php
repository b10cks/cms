<?php

namespace App\Http\Requests\Content;

use App\Enums\ContentTranslationImportMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MassEditSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'documents' => ['required', 'array', 'min:1'],
            'documents.*.content_id' => ['required', 'string'],
            'documents.*.targets' => ['required', 'array'],
            'mode' => ['sometimes', Rule::enum(ContentTranslationImportMode::class)],
            'create_missing' => ['sometimes', 'boolean'],
        ];
    }

    public function getMode(): ContentTranslationImportMode
    {
        return ContentTranslationImportMode::tryFrom((string) $this->input('mode'))
            ?? ContentTranslationImportMode::DRAFT;
    }

    public function shouldCreateMissing(): bool
    {
        return $this->boolean('create_missing', true);
    }
}
