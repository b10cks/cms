<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactorAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTotpVerification
{
    public function __construct(private TwoFactorAuthService $twoFactorService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'message' => __('auth.unauthenticated'),
            ], 401);
        }

        if (!$user->hasEnabledTwoFactor()) {
            return $this->handlePasswordVerification($request, $next, $user);
        }

        if ($this->twoFactorService->hasGracePeriod($user->id)) {
            return $next($request);
        }

        $totpCode = $request->header('X-TOTP-Code');

        if (!$totpCode) {
            return response()->json([
                'message' => __('auth.2fa_required'),
                'error_code' => 'TOTP_VERIFICATION_REQUIRED',
                'requires_2fa' => true,
            ], 423);
        }

        if (!$this->twoFactorService->verifyTotp($user, $totpCode)) {
            return response()->json([
                'message' => __('auth.invalid_2fa_code'),
                'error_code' => 'INVALID_TOTP_CODE',
            ], 403);
        }

        $this->twoFactorService->setGracePeriod($user->id, config('auth.2fa_grace_period', 30));

        return $next($request);
    }

    private function handlePasswordVerification(Request $request, Closure $next, $user): Response
    {
        $password = $request->header('X-Password-Confirmation');

        if (!$password) {
            return response()->json([
                'message' => __('auth.password_confirmation_required'),
                'error_code' => 'PASSWORD_CONFIRMATION_REQUIRED',
                'requires_password' => true,
            ], 423);
        }

        if (!password_verify($password, $user->password)) {
            return response()->json([
                'message' => __('auth.invalid_password'),
                'error_code' => 'INVALID_PASSWORD',
            ], 403);
        }

        return $next($request);
    }
}
