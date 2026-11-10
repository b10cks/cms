<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

abstract class AuthController extends Controller
{
    /**
     * A login proven by an external provider (social or SAML) that still owes
     * the account's own second factor. `SocialLoginController::verifyTwoFactor`
     * finishes it.
     */
    protected const string PENDING_2FA_USER_ID = 'auth.social.pending_2fa_user_id';

    protected function updateUserLogin(User $user): void
    {
        $user->forceFill([
            'login_count' => $user->login_count + 1,
            'last_login_at' => now(),
        ])
            ->save();
    }

    protected function responseWithAccessToken(string $token): \Illuminate\Http\JsonResponse
    {
        return response()
            ->json([
                'access_token' => $token,
                'token_type' => 'bearer',
            ]);
    }

    protected function logoutSession(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /** The 429 a sign-in gets while the account's TOTP attempts are used up, else null. */
    protected function totpLockoutResponse(User $user): ?JsonResponse
    {
        $seconds = app(TwoFactorAuthService::class)->totpLockoutSeconds($user);

        if ($seconds === 0) {
            return null;
        }

        return response()->json([
            'message' => __('auth.too_many_verification_attempts', ['seconds' => $seconds]),
            'error_code' => 'TOO_MANY_ATTEMPTS',
            'retry_after' => $seconds,
        ], 429);
    }
}
