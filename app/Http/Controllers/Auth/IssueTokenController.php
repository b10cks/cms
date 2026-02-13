<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Notifications\User\VerifyEmailNotification;
use App\Services\Auth\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;


class IssueTokenController extends AuthController
{
    private const string VERIFICATION_CACHE_PREFIX = 'email_verification:';
    private const int VERIFICATION_CACHE_TTL = 3600;
    private const int RATE_LIMIT_TTL = 60;

    public function __construct(private TwoFactorAuthService $twoFactorService)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email:rfc,filter',
            'password' => 'required|string',
        ]);

        $credentials = $request->only(['email', 'password']);

        if (!Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            return response()->json(['message' => __('auth.failed')], 401);
        }

        $user = Auth::guard('web')->user();
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

            if (!$this->twoFactorService->verifyTotp($user, $totpCode)) {
                $this->logoutSession($request);

                return response()->json([
                    'message' => __('auth.invalid_2fa_code'),
                    'error_code' => 'INVALID_TOTP_CODE',
                ], 403);
            }
        }

        if (!$user->email_verified_at) {
            $this->sendVerificationEmail($user);
            $this->logoutSession($request);

            return response()->json([
                'message' => __('auth.email_not_verified'),
                'error_code' => 'EMAIL_NOT_VERIFIED',
                'requires_verification' => true,
            ], 409);
        }

        $this->updateUserLogin($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'message' => __('auth.login_successful'),
        ]);
    }

    private function sendVerificationEmail(User $user): void
    {
        $rateLimitKey = self::VERIFICATION_CACHE_PREFIX . 'rate_limit:' . $user->id;

        if (Cache::has($rateLimitKey)) {
            return;
        }

        $verificationUrl = $this->generateVerificationUrl($user);
        $user->notify(new VerifyEmailNotification($verificationUrl));

        Cache::put($rateLimitKey, now()->addSeconds(self::RATE_LIMIT_TTL)->timestamp, self::RATE_LIMIT_TTL);
    }

    private function generateVerificationUrl(User $user): string
    {
        $cacheKey = self::VERIFICATION_CACHE_PREFIX . $user->id;

        return Cache::remember($cacheKey, self::VERIFICATION_CACHE_TTL, function () use ($user) {
            return URL::signedRoute(
                'verification.verify',
                [
                    'id' => $user->getRouteKey(),
                    'hash' => sha1($user->email),
                ],
                now()->addMinutes(60)
            );
        });
    }

    protected function logoutSession(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
