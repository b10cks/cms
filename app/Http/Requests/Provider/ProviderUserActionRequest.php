<?php

namespace App\Http\Requests\Provider;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Base for the provider console's user actions. They are root writes, so they
 * need a running elevation, and never apply to root accounts or yourself.
 */
class ProviderUserActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isElevatedRoot();
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $subject = $this->subject();

                if ($subject->is_root || $subject->id === $this->user()->id) {
                    $validator->errors()->add('user', __('provider.cannot_target_root'));
                }
            },
        ];
    }

    public function subject(): User
    {
        /** @var User $user */
        $user = $this->route('user');

        return $user;
    }
}
