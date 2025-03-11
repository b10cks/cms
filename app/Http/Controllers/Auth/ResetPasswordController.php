<?php

namespace App\Http\Controllers\Auth;

use App\Actions\User\ChangePassword;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ResetPasswordController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
                               'token' => 'required',
                               'email' => 'required|email:rfc,filter',
                               'password' => 'required|min:8|confirmed',
                           ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                app(ChangePassword::class)->execute($user, $password);
            }
        );

        return $status === Password::PASSWORD_RESET
            ? $this->sendResetResponse($request, $status)
            : $this->sendResetFailedResponse($request, $status);
    }

    protected function sendResetResponse(Request $request, $response)
    {
        return response(['message' => __($response)], 200);
    }

    protected function sendResetFailedResponse(Request $request, $response)
    {
        return response(['errors' => ['email' => [__($response)]]], 422);
    }
}
