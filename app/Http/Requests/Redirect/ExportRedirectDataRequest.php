<?php

namespace App\Http\Requests\Redirect;

use App\Enums\ImportExportFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportRedirectDataRequest extends FormRequest
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
                Rule::in([
                    ImportExportFormat::CSV->value,
                    ImportExportFormat::EXCEL->value,
                    ImportExportFormat::JSON->value,
                    ImportExportFormat::YAML->value,
                ]),
            ],
        ];
    }
}
