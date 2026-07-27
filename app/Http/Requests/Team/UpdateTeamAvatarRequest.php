<?php

namespace App\Http\Requests\Team;

use App\Http\Requests\Traits\RejectsActiveContentUploads;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamAvatarRequest extends FormRequest
{
    use RejectsActiveContentUploads;

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
                // No SVG: it is an active-content document, and these files are served
                // from the application origin.
                'mimes:jpeg,png,jpg,gif,webp',
                'max:2048',
                $this->rejectActiveContentRule(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.required' => 'An avatar image is required',
            'avatar.image' => 'The file must be an image',
            'avatar.mimes' => 'The avatar must be a file of type: jpeg, png, jpg, gif, webp',
            'avatar.max' => 'The avatar may not be greater than 2MB',
        ];
    }
}
