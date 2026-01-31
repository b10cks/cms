<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\User\TwoFactorDisabledNotification;
use App\Services\Auth\TwoFactorAuthService;

class DisableTwoFactor
{
    public function __construct(private TwoFactorAuthService $twoFactorService)
    {
    }

    public function execute(User $user): array
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_backup_codes' => null,
            'two_factor_enabled_at' => null,
        ])->save();

        $this->twoFactorService->clearGracePeriod($user->id);

        $user->notify(new TwoFactorDisabledNotification());

        return [
            'success' => true,
            'message' => __('auth.2fa_disabled'),
        ];
    }
}
