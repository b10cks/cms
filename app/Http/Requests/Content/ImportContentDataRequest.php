<?php

namespace App\Http\Requests\Content;

use App\Enums\ContentTranslationImportMode;
use App\Enums\ImportExportFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportContentDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:102400'],
            'import_mode' => ['sometimes', Rule::enum(ContentTranslationImportMode::class)],
            'create_missing' => ['sometimes', 'boolean'],
            'grid' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File is required for import',
            'file.file' => 'Upload must be a valid file',
            'file.max' => 'File size exceeds maximum allowed size (100 MB)',
        ];
    }

    public function getContentDataFormat(): ImportExportFormat
    {
        return ImportExportFormat::fromExtension(
            strtolower($this->file('file')->getClientOriginalExtension())
        );
    }

    public function getImportMode(): ContentTranslationImportMode
    {
        return ContentTranslationImportMode::tryFrom((string) $this->input('import_mode'))
            ?? ContentTranslationImportMode::DRAFT;
    }

    public function shouldCreateMissing(): bool
    {
        return $this->boolean('create_missing');
    }

    /**
     * A file exported from the mass-edit grid carries the source column and
     * non-translatable fields. Importing it without this flag would silently drop
     * exactly those cells, so the round trip has to say which shape it is.
     */
    public function isGridImport(): bool
    {
        return $this->boolean('grid');
    }
}
