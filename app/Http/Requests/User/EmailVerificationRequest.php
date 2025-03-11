<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EmailVerificationRequest extends FormRequest
{
    private ?User $user;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        $this->user = User::findByHashId($this->route('id'));
        if (!$this->user) {
            return false;
        }

        if (!hash_equals((string)$this->user->getRouteKey(), (string)$this->route('id'))) {
            return false;
        }

        if (!hash_equals(sha1($this->user->getEmailForVerification()), (string)$this->route('hash'))) {
            return false;
        }

        return true;
    }

    /**
     * Fulfill the email verification request.
     *
     * @return void
     */
    public function fulfill()
    {
        if (!$this->user->hasVerifiedEmail()) {
            $this->user->markEmailAsVerified();

            event(new Verified($this->user));
        }
    }

    /**
     * Configure the validator instance.
     *
     * @param Validator $validator
     * @return Validator
     */
    public function withValidator(Validator $validator)
    {
        return $validator;
    }
}
