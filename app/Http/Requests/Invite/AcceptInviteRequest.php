<?php

namespace App\Http\Requests\Invite;

use Illuminate\Foundation\Http\FormRequest;

class AcceptInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Optional: authenticated invitees are verified by email match;
            // a supplied token must still match the mailed link.
            'token' => 'nullable|string',
        ];
    }
}
