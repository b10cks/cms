<?php

namespace App\Http\Requests\Content;

use App\Enums\ImportExportFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportContentDataRequest extends FormRequest
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
            'grid' => ['sometimes', 'boolean'],
            // Grid mode mirrors the mass-edit selection, so without `fields` it would
            // dump every unit of every field instead of the columns that were on screen.
            'fields' => ['required_if:grid,1,true', 'nullable', 'string'],
            'languages' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'as.required' => 'Export format is required',
            'as.enum' => 'Invalid export format. Supported formats: csv, excel, json, xliff, yaml',
            'fields.required_if' => 'A grid export needs the selected field keys.',
        ];
    }

    /**
     * @return array<int, string>|null
     */
    public function getFieldKeys(): ?array
    {
        return $this->parseList('fields');
    }

    /**
     * @return array<int, string>|null
     */
    public function getLanguageFilter(): ?array
    {
        return $this->parseList('languages');
    }

    /**
     * @return array<int, string>|null
     */
    private function parseList(string $key): ?array
    {
        $value = trim((string) $this->input($key, ''));

        if ($value === '') {
            return null;
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $value))));

        return $items === [] ? null : $items;
    }
}
