<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserAvatarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only allow users to update their own avatar
        return auth()->id() === $this->user()->id;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,png,jpg,gif',
                'max:2048', // 2MB max file size
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'An avatar image is required',
            'avatar.image' => 'The file must be an image',
            'avatar.mimes' => 'The image must be a file of type: jpeg, png, jpg, gif',
            'avatar.max' => 'The image may not be greater than 2MB',
        ];
    }
}
