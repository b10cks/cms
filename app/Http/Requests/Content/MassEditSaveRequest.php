<?php

namespace App\Http\Requests\Content;

use App\Enums\ContentTranslationImportMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MassEditSaveRequest extends FormRequest
{
    /**
     * Every (document, language) pair writes a content version, so a request has to
     * stay small enough to finish inside one HTTP timeout. The grid chunks its saves
     * client side; this is the backstop for anything else calling the endpoint.
     */
    public const MAX_DOCUMENTS = 100;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'documents' => ['required', 'array', 'min:1', 'max:'.self::MAX_DOCUMENTS],
            'documents.*.content_id' => ['required', 'string'],
            'documents.*.targets' => ['required', 'array'],
            'documents.*.targets.*' => ['array'],
            'documents.*.targets.*.*' => ['nullable', 'string'],
            'mode' => ['sometimes', Rule::enum(ContentTranslationImportMode::class)],
            'create_missing' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'documents.max' => 'A single mass-edit save is limited to '.self::MAX_DOCUMENTS.' contents. Send the changes in batches.',
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
