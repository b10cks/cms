<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'old_password' => 'required|string',
            'password' => 'required|string|confirmed',
        ];
    }
}
