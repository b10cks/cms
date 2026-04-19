<?php

namespace App\Http\Requests\Asset;

use App\Enums\ImportExportFormat;
use Illuminate\Foundation\Http\FormRequest;

class ImportAssetDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:102400',
            ],
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

    public function getAssetDataFormat(): ImportExportFormat
    {
        return ImportExportFormat::fromExtension(
            strtolower($this->file('file')->getClientOriginalExtension())
        );
    }
}
