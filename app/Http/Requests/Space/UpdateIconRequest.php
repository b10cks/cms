<?php

namespace App\Http\Requests\Space;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIconRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $space = $this->route('space');

        return $this->user()->can('update', $space);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'icon' => [
                'required',
                'file',
                'image',
                // No SVG: it is an active-content document, and these files are served
                // from the application origin.
                'mimes:jpeg,png,jpg,gif,webp',
                'max:2048',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'icon.required' => 'An icon image is required',
            'icon.image' => 'The file must be an image',
            'icon.mimes' => 'The icon must be a file of type: jpeg, png, jpg, gif',
            'icon.max' => 'The icon may not be greater than 2MB',
        ];
    }
}
