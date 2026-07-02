<?php

namespace App\Http\Requests\Icon;

use App\Enums\IconImportMode;
use Illuminate\Foundation\Http\FormRequest;

class ImportIconDataRequest extends FormRequest
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

    public function getImportMode(): IconImportMode
    {
        return IconImportMode::from($this->input('import_mode', 'addition'));
    }
}
