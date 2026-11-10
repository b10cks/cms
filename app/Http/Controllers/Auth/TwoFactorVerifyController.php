<?php

namespace App\Http\Controllers\Auth;

use App\Services\Auth\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorVerifyController extends AuthController
{
    public function __construct(private TwoFactorAuthService $twoFactorService)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = auth()->user();

        if (!$user->hasEnabledTwoFactor()) {
            return response()->json([
                'message' => __('auth.2fa_not_enabled'),
            ], 400);
        }

        if ($lockout = $this->totpLockoutResponse($user)) {
            return $lockout;
        }

        // A TOTP or a backup code; a used backup code is removed.
        $backupCodes = count($user->two_factor_backup_codes ?? []);

        if ($this->twoFactorService->attemptTotp($user, (string) $request->input('code'))) {
            $this->twoFactorService->setGracePeriod($request, $user, config('auth.2fa_grace_period', 30));

            return response()->json([
                'message' => count($user->two_factor_backup_codes ?? []) < $backupCodes
                    ? __('auth.2fa_verified_backup_code_used')
                    : __('auth.2fa_verified'),
            ]);
        }

        return response()->json([
            'message' => __('auth.invalid_2fa_code'),
        ], 422);
    }
}
