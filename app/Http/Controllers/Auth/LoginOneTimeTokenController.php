<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginOneTimeTokenController extends AuthController
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'email' => 'required|email:rfc,filter|exists:users,email',
            'token' => 'required|string|min:6|max:6',
        ]);

        $user = User::where('email', $request->email)->firstOrFail();
        $cacheKey = 'user:' . $user->id . ':one-time-token';

        $tokens = \Cache::get($cacheKey, []);
        if (!in_array($request->token, $tokens)) {
            return response(['message' => __('auth.failed')], 401);
        }

        Auth::guard('web')->login($user);

        if ($user->hasEnabledTwoFactor()) {
            $totpCode = $request->header('X-TOTP-Code');
            if (!$totpCode) {
                $this->logoutSession($request);

                return response()->json([
                    'message' => __('auth.2fa_required'),
                    'error_code' => 'TOTP_VERIFICATION_REQUIRED',
                    'requires_2fa' => true,
                ], 423);
            }

            if (!app(\App\Services\Auth\TwoFactorAuthService::class)->verifyTotp($user, $totpCode)) {
                $this->logoutSession($request);

                return response()->json([
                    'message' => __('auth.invalid_2fa_code'),
                    'error_code' => 'INVALID_TOTP_CODE',
                ], 403);
            }
        }

        $this->updateUserLogin($user);
        \Cache::delete($cacheKey);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'message' => __('auth.login_successful'),
        ]);
    }
}
