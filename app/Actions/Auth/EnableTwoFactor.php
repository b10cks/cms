<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\User\TwoFactorEnabledNotification;
use App\Services\Auth\TwoFactorAuthService;
use Illuminate\Support\Facades\Cache;

class EnableTwoFactor
{
    public function __construct(private TwoFactorAuthService $twoFactorService)
    {
    }

    public function execute(User $user, string $code, string $secret): array
    {
        if (!$this->twoFactorService->verify($secret, $code)) {
            return [
                'success' => false,
                'message' => __('auth.invalid_2fa_code'),
            ];
        }

        $backupCodes = $this->twoFactorService->generateBackupCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_backup_codes' => $backupCodes,
            'two_factor_enabled_at' => now(),
        ])->save();

        $user->notify(new TwoFactorEnabledNotification());

        return [
            'success' => true,
            'message' => __('auth.2fa_enabled'),
            'backup_codes' => $backupCodes,
        ];
    }
}
