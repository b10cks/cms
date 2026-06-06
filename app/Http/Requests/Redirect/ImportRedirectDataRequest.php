<?php

namespace App\Http\Requests\Redirect;

use App\Enums\ImportExportFormat;
use App\Enums\RedirectImportMode;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

class ImportRedirectDataRequest extends FormRequest
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
            'import_mode' => [
                'nullable',
                'string',
                'in:addition,replacement',
            ],
        ];
    }

    public function getImportMode(): RedirectImportMode
    {
        return RedirectImportMode::from($this->input('import_mode', 'addition'));
    }

    public function getRedirectDataFormat(): ImportExportFormat
    {
        $extension = strtolower($this->file('file')->getClientOriginalExtension());

        return match ($extension) {
            'csv' => ImportExportFormat::CSV,
            'xlsx', 'xls' => ImportExportFormat::EXCEL,
            'json' => ImportExportFormat::JSON,
            'yaml', 'yml' => ImportExportFormat::YAML,
            default => throw new InvalidArgumentException(
                "Unsupported file format: .{$extension}. Supported formats: csv, xlsx, xls, json, yaml, yml"
            ),
        };
    }
}
