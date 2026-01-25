<?php

namespace App\Http\Requests\Asset;

use App\Enums\AssetDataFormat;
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

    public function getAssetDataFormat(): AssetDataFormat
    {
        $extension = strtolower($this->file('file')->getClientOriginalExtension());

        return match ($extension) {
            'csv' => AssetDataFormat::CSV,
            'xlsx', 'xls' => AssetDataFormat::EXCEL,
            'json' => AssetDataFormat::JSON,
            'xlf', 'xliff', 'xml' => AssetDataFormat::XLIFF,
            'yaml', 'yml' => AssetDataFormat::YAML,
            default => throw new \InvalidArgumentException("Unsupported file format: .{$extension}. Supported formats: csv, xlsx, xls, json, xlf, xliff, yaml, yml"),
        };
    }
}
