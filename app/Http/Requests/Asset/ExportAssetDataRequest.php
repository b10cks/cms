<?php

namespace App\Http\Requests\Asset;

use App\Enums\ImportExportFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportAssetDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'as' => [
                'required',
                'string',
                Rule::enum(ImportExportFormat::class),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'as.required' => 'Export format is required',
            'as.enum' => 'Invalid export format. Supported formats: csv, excel, json, xliff',
        ];
    }
}
