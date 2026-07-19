<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('team'));
    }

    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,png,jpg,gif,svg,webp',
                'max:2048',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.required' => 'An avatar image is required',
            'avatar.image' => 'The file must be an image',
            'avatar.mimes' => 'The avatar must be a file of type: jpeg, png, jpg, gif, svg, webp',
            'avatar.max' => 'The avatar may not be greater than 2MB',
        ];
    }
}
