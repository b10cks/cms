<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StartImpersonationRequest extends FormRequest
{
    /** Signing in as someone else is a root write: it needs a running elevation. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->isElevatedRoot();
    }

    public function rules(): array
    {
        return [
            'userId' => ['required', 'string', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $target = $this->target();

                if ($target === null) {
                    return;
                }

                if ($target->id === $this->user()->id || $target->is_root) {
                    $validator->errors()->add('userId', __('provider.impersonate_root'));
                } elseif ($target->isSuspended()) {
                    $validator->errors()->add('userId', __('provider.impersonate_suspended'));
                }
            },
        ];
    }

    public function target(): ?User
    {
        return User::query()->find($this->input('userId'));
    }
}
