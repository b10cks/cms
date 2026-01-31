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

        $code = $request->input('code');

        if ($this->verifyTotpCode($user, $code)) {
            $this->twoFactorService->setGracePeriod($user->id, config('auth.2fa_grace_period', 30));

            return response()->json([
                'message' => __('auth.2fa_verified'),
            ]);
        }

        if ($this->verifyBackupCode($user, $code)) {
            $this->twoFactorService->setGracePeriod($user->id, config('auth.2fa_grace_period', 30));

            return response()->json([
                'message' => __('auth.2fa_verified_backup_code_used'),
            ]);
        }

        return response()->json([
            'message' => __('auth.invalid_2fa_code'),
        ], 422);
    }

    private function verifyTotpCode($user, string $code): bool
    {
        return $this->twoFactorService->verify($user->two_factor_secret, $code);
    }

    private function verifyBackupCode($user, string $code): bool
    {
        $backupCodes = $user->two_factor_backup_codes;

        if (!$this->twoFactorService->verifyBackupCode($backupCodes, $code)) {
            return false;
        }

        $updatedCodes = $this->twoFactorService->removeUsedBackupCode($backupCodes, $code);
        $user->forceFill([
            'two_factor_backup_codes' => $updatedCodes,
        ])->save();

        return true;
    }
}
