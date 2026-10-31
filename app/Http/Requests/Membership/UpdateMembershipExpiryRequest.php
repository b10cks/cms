<?php

namespace App\Http\Requests\Membership;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class UpdateMembershipExpiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'access_until' => ['present', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    /** Access ends with the chosen day; null keeps it open-ended. */
    public function expiresAt(): ?Carbon
    {
        return $this->filled('access_until')
            ? Carbon::createFromFormat('Y-m-d', $this->input('access_until'))->endOfDay()
            : null;
    }
}
