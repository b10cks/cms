<?php

namespace App\Http\Requests\Space;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDataEntryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        $dataSource = $this->route('data_source');
        $dataEntry = $this->route('entry');

        return [
            'key' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9._\-]+$/',
//                Rule::unique( new DataEntry()->getConnectionName() . '.data_entries', 'key')
//                    ->where('data_source_id', $dataSource->id)
//                    ->ignore($dataEntry->id),
            ],
            'value' => 'sometimes|nullable|string',
            'dimensions' => 'nullable|array',
            'dimensions.*' => 'nullable|string',
        ];
    }
}
