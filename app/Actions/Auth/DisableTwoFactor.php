<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\User\TwoFactorDisabledNotification;

class DisableTwoFactor
{
    public function execute(User $user): array
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_backup_codes' => null,
            'two_factor_enabled_at' => null,
        ])->save();

        $user->notify(new TwoFactorDisabledNotification());

        return [
            'success' => true,
            'message' => __('auth.2fa_disabled'),
        ];
    }
}
